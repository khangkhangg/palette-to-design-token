import { buildTokens } from '../analyse/build';
import { collect } from './collect';
import type { Tokens } from '../model/tokens';

(globalThis as unknown as { __sdtScan: () => Tokens }).__sdtScan = () => buildTokens(collect(document));
