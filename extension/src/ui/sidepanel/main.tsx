import { render } from 'preact';
import '../theme.css';
import { App } from './App';
import { viewFromSearch } from './open';

render(<App initialView={viewFromSearch(location.search)} />, document.getElementById('app')!);
