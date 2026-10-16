import { render } from 'preact';
import { t } from './ui/i18n';

render(<h1>{t('app.name')}</h1>, document.getElementById('app')!);
