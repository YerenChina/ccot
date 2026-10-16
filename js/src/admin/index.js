import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import CCOTPage from './CCOTPage';
export const extend = [new Extend.Admin().page(CCOTPage)];
app.initializers.add('bushcraftcn-ccot', () => {});
