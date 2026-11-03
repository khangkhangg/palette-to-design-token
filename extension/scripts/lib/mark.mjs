/** The product mark: rounded square, conic gradient (#1F5E4B, #E0763A, #3B4BD8), soft inner highlight. No text. */
export const MARK_CSS = `
.mark{position:relative;border-radius:22.5%;overflow:hidden;
  background:conic-gradient(from 200deg,#1F5E4B,#E0763A,#3B4BD8,#1F5E4B)}
.mark::after{content:'';position:absolute;inset:0;border-radius:inherit;
  background:linear-gradient(160deg,rgba(255,255,255,.34) 0%,rgba(255,255,255,0) 46%),
             radial-gradient(circle at 50% 120%,rgba(0,0,0,.18),rgba(0,0,0,0) 60%);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.22)}`;

/** Light-theme tokens from src/ui/theme.css, for pages composed outside the extension. */
export const TOKENS = {
  bg: '#FBFBFA', ink: '#16181D', ink2: '#4A4F5C', muted: '#5D6270', line: '#E3E4E8', accent: '#3B4BD8',
  green: '#1F5E4B', orange: '#E0763A',
};
