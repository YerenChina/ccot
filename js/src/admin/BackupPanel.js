import Component from 'flarum/common/Component';
import t from '../common/t';

export default class BackupPanel extends Component {
  view() {
    const items = [
      [t('lib.backup_database'), t('lib.backup_database_help')],
      [t('lib.backup_configuration'), t('lib.backup_configuration_help')],
      [t('lib.backup_key'), t('lib.backup_key_help')],
      [t('lib.backup_sms'), t('lib.backup_sms_help')],
      [t('lib.backup_ticket_images'), t('lib.backup_ticket_images_help')],
      [t('lib.backup_post_images'), t('lib.backup_post_images_help')],
      [t('lib.backup_other_files'), t('lib.backup_other_files_help')],
    ];
    return m('section.CCOT-backup', [
      m('h2', t('lib.backup_title')),
      m('p', t('lib.backup_intro')),
      m('section.CCOT-account-section', [
        m('h3', t('lib.backup_contents')),
        m('p.CCOT-help', t('lib.backup_paths')),
        m('dl.CCOT-backup-list', items.map(([label, detail]) => [m('dt', label), m('dd', detail)])),
      ]),
      m('section.CCOT-account-section', [
        m('h3', t('lib.backup_key_purpose')),
        m('p', t('lib.backup_key_scope')),
        m('p', t('lib.backup_key_boundary')),
        m('p.Alert', t('lib.backup_key_loss')),
      ]),
      m('section.CCOT-account-section', [
        m('h3', t('lib.backup_procedure')),
        m('ol', [m('li', t('lib.backup_step_save')), m('li', t('lib.backup_step_restore')), m('li', t('lib.backup_step_check'))]),
        m('p', t('lib.backup_privacy')),
        m('p.CCOT-help', t('lib.backup_mapping_scope')),
        m('a', { href: 'https://github.com/YerenChina/ccot#备份与恢复', target: '_blank', rel: 'noopener noreferrer' }, t('lib.backup_readme')),
      ]),
    ]);
  }
}
