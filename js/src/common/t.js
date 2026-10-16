import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';

// CCOT messages are plain text; use the same helper for labels, attributes and notices.
export default function t(key, parameters = {}) {
  return extractText(app.translator.trans(`bushcraftcn-ccot.${key}`, parameters));
}
