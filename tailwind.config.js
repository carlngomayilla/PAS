export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    safelist: [
        'bg-anbg-navy',
        'bg-anbg-blue',
        'bg-anbg-green',
        'bg-anbg-yellow',
        'bg-anbg-gold',
        'bg-anbg-orange',
        'text-anbg-navy',
        'text-anbg-blue',
        'border-anbg-navy',
    ],
    theme: {
        extend: {
            colors: {
                anbg: {
                    navy: '#1c203d',
                    blue: '#3996d3',
                    green: '#8fc043',
                    yellow: '#f8e932',
                    gold: '#f0e509',
                    orange: '#f9b13c',
                    soft: '#eef6fc',
                },
                primary: '#1c203d',
                primaryBlue: '#3996d3',
                primaryLight: '#eef6fc',
                primarySoft: 'rgba(57, 150, 211, 0.12)',
                borderSoft: '#d8ecf8',
                textMain: '#1c203d',
                textMuted: '#667085',
            },
            fontFamily: {
                sans: ['Public Sans', 'Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
            },
            boxShadow: {
                app: '0 16px 40px rgba(57, 150, 211, 0.10)',
                soft: '0 8px 24px rgba(57, 150, 211, 0.08)',
            },
            borderRadius: {
                app: '1rem',
            },
        },
    },
    plugins: [],
};
