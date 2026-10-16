<?php

namespace BushcraftCN\CCOT;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

final class UserFields
{
    public function __construct(private ConnectionInterface $db, private Crypto $crypto) {}

    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('ccotPhoneVerified')->get(fn (User $user) => $this->db->table('ccot_phones')->where('user_id', $user->id)->exists()),
            Schema\Str::make('ccotRegion')->get(fn (User $user) => $this->db->table('ccot_phones')->where('user_id', $user->id)->value('region') ?? I18n::t('lib.unknown')),
            Schema\Str::make('ccotPhoneMasked')
                ->visible(fn (User $user, Context $context) => $context->getActor()->id === $user->id || $context->getActor()->isAdmin())
                ->get(function (User $user) {
                    $cipher = $this->db->table('ccot_phones')->where('user_id', $user->id)->value('cipher');
                    return $cipher ? $this->crypto->mask($this->crypto->decrypt($cipher)) : null;
                })->nullable(),
            Schema\Boolean::make('ccotPhoneRestricted')
                ->visible(fn (User $user, Context $context) => $context->getActor()->isAdmin())
                ->get(function (User $user) {
                    $hash = $this->db->table('ccot_phones')->where('user_id', $user->id)->value('phone_hash');
                    return $hash && $this->db->table('ccot_blocks')->where('phone_hash', $hash)->where('expires_at', '>', time())->exists();
                }),
        ];
    }

    public static function optionalEmail(Schema\Str $field): Schema\Str
    {
        $original = $field->setter;
        $rules = $field->getRules();
        $field->rules([], true);
        foreach ($rules as $rule) {
            if ($rule['rule'] !== 'required_without:token') $field->rule($rule['rule'], $rule['condition']);
        }
        return $field->nullable()->rule('nullable')->set(function (User $user, ?string $value, Context $context) use ($original) {
            if ($context->creating() && $value === null) {
                $user->email = null;
            } elseif ($value !== null) {
                $original($user, $value, $context);
            } else {
                throw new \Flarum\Foundation\ValidationException(['email' => I18n::t('api.use_a_personal_information_request_to_remove_an_email_address')]);
            }
        });
    }
}
