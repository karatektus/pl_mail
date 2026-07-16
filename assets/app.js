import './stimulus_bootstrap.js';
import './styles/app.css';
import '@fortawesome/fontawesome-free/css/all.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

// Exposed globally so the inline <script> in _topbar and any other code
// can call it after toggling the .dark class.
window.syncThemeIcon = function() {
    const dark = document.documentElement.classList.contains('dark');
    const moon = document.getElementById('icon-moon');
    const sun  = document.getElementById('icon-sun');
    if (moon) { moon.classList.toggle('!hidden', dark); }
    if (sun)  { sun.classList.toggle('!hidden', !dark); }
};

document.addEventListener('turbo:load', function() {
    // Re-sync icons after every Turbo navigation (the header is turbo-permanent
    // so the inline script won't re-run, but the .dark class is already correct).
    window.syncThemeIcon();

    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const nowDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', nowDark ? 'dark' : 'light');
            window.syncThemeIcon();
        });
    }

    // User menu toggle
    const btn  = document.getElementById('user-menu-btn');
    const menu = document.getElementById('user-menu');

    if (btn && menu) {
        btn.addEventListener('click', function() {
            menu.classList.toggle('hidden');
        });

        document.addEventListener('click', function(event) {
            if (!btn.contains(event.target) && !menu.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });
    }
});
