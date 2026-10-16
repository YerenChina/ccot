<?php

namespace BushcraftCN\CCOT;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class SmsAdminController implements RequestHandlerInterface
{
    public function __construct(private SmsConfig $config, private Otp $otp, private Crypto $crypto, private Limiter $limits, private ConnectionInterface $db, private LoggerInterface $log, private SmsGateway $sms) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);
            if (! $actor->isAdmin()) throw new Problem(I18n::t('api.sms_administration_permission_required'), 403);
            $body = $request->getParsedBody() ?? [];
            $action = basename($request->getUri()->getPath());
            if ($action === 'sms-settings') {
                if ($request->getMethod() === 'POST') {
                    $this->config->save($body);
                    $this->log->info('CCOT SMS configuration saved', ['actor_id' => $actor->id]);
                }
                $status = $this->config->publicStatus();
                $status['test_used'] = $status['grant_id'] ? $this->limits->scopedCount($status['grant_id']) : 0;
                $phone = $this->config->read()['test_phone'];
                $status['test_ready'] = false;
                $status['test_block_reason'] = '';
                try { $this->sms->assertReady($phone); $status['test_ready'] = true; }
                catch (Problem $error) { $status['test_block_reason'] = $error->getMessage(); }
                $status['test_retry_after'] = $phone ? $this->limits->cooldownRemaining('sms.phone.interval', $this->crypto->digest('phone', $phone)) : 0;
                return PrivateResponse::json($status);
            }
            $phone = $this->config->read()['test_phone'];
            if (! $phone) throw new Problem(I18n::t('api.save_the_test_recipient_phone_number_first'), 422);
            if ($action === 'sms-test') {
                $ip = (string) $request->getAttribute('ipAddress', $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
                $challenge = $this->otp->issue($phone, 'test', $ip, $actor->id);
                $message = I18n::t('api.the_send_request_was_accepted_enter_the_code_received_on');
                return PrivateResponse::json(['challenge' => $challenge, 'message' => $message]);
            }
            if ($action === 'sms-test-verify') {
                $challenge = (string) ($body['challenge'] ?? '');
                $this->otp->attempt($challenge);
                $this->db->transaction(fn () => $this->otp->consume($challenge, $phone, (string) ($body['code'] ?? ''), 'test', $actor->id));
                $this->log->info('CCOT SMS test verified', ['actor_id' => $actor->id, 'challenge_id' => $challenge]);
                return PrivateResponse::json(['verified' => true, 'message' => I18n::t('api.code_verified_this_test_did_not_change_any_account_binding')]);
            }
            throw new Problem(I18n::t('api.endpoint_not_found'), 404);
        } catch (Problem $error) {
            return PrivateResponse::json(['errors' => [['status' => (string) $error->status, 'detail' => $error->getMessage(), 'providerCode' => $error->providerCode, 'retryAfter' => $error->retryAfter]]], $error->status, $error->retryAfter ? ['Retry-After' => (string) $error->retryAfter] : []);
        } catch (\Throwable) {
            return PrivateResponse::json(['errors' => [['status' => '503', 'detail' => I18n::t('api.sms_settings_or_testing_are_unavailable_check_the_private_directory')]]], 503);
        }
    }
}
