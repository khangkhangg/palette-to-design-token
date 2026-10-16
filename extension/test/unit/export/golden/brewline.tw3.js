/** @type {import('tailwindcss').Config} */
export default {
  theme: {
    extend: {
      colors: {
        primary: "#1F5E4B",
        "primary-2": "#2E7A63",
        accent: "#E0763A",
        surface: "#FFFDF8",
        "surface-2": "#F3EEE4",
        text: "#1B1A17",
        "text-2": "#5C574E",
        border: "#E4DDD0",
      },
      fontFamily: {
        body: ["DM Sans", "sans-serif"],
        heading: ["Fraunces", "serif"],
      },
      fontSize: {
        display: ["48px", { lineHeight: "56px", fontWeight: "600" }],
        h1: ["24px", { lineHeight: "32px", fontWeight: "600" }],
        body: ["16px", { lineHeight: "26px", fontWeight: "400" }],
        small: ["13px", { lineHeight: "18px", fontWeight: "500" }],
      },
      spacing: {
        // Named space-1, space-2… (p-space-3, gap-space-2) so they add to Tailwind's numeric scale instead of replacing p-1, p-2…
        "space-1": "4px",
        "space-2": "8px",
        "space-3": "12px",
        "space-4": "16px",
        "space-5": "24px",
        "space-6": "32px",
        "space-7": "48px",
      },
      borderRadius: {
        sm: "4px",
        md: "10px",
        full: "9999px",
      },
      boxShadow: {
        sm: "0 1px 2px rgba(0, 0, 0, 0.5)",
        md: "0 6px 16px rgba(0, 0, 0, 0.6)",
      },
    },
  },
};
