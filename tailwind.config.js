/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,jsx}",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: '#710014',
          hover:   '#8B0019',
          light:   '#F9E5E7',
        },
        surface: {
          1: '#F7F7F6',
          2: '#EFEFED',
        },
        'c-border': {
          DEFAULT: '#D9D9D6',
          strong:  '#9C9A92',
        },
        'text-main':  '#3D3D3A',
        'text-sub':   '#5C5C5A',
        'text-muted': '#9C9A92',
        success: {
          DEFAULT: '#0F6E56',
          light:   '#E1F5EE',
        },
        warning: {
          DEFAULT: '#854F0B',
          light:   '#FAEEDA',
        },
        info: {
          DEFAULT: '#185FA5',
          light:   '#E6F1FB',
        },
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      },
      fontSize: {
        '2xs': '11px',
        xs:    '12px',
        sm:    '13px',
        base:  '14px',
        md:    '15px',
        lg:    '16px',
        xl:    '18px',
        '2xl': '20px',
        '3xl': '22px',
      },
      borderWidth: {
        DEFAULT: '0.5px',
        1:       '1px',
      },
      borderRadius: {
        sm:  '4px',
        md:  '6px',
        lg:  '8px',
        xl:  '12px',
        full:'9999px',
      },
      keyframes: {
        fadeIn: {
          from: { opacity: '0' },
          to:   { opacity: '1' },
        },
      },
      animation: {
        fadeIn: 'fadeIn 0.25s ease-out',
      },
    },
  },
  plugins: [],
}