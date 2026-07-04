import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';
import '@fortawesome/fontawesome-free/css/all.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

document.addEventListener('turbo:load', function() {
    const html = document.documentElement;
    const isDark = html.classList.contains('dark');
    document.getElementById('icon-sun').classList.toggle('hidden', !isDark);
    document.getElementById('icon-moon').classList.toggle('hidden', isDark);

    document.getElementById('theme-toggle').addEventListener('click', function () {
        const nowDark = html.classList.toggle('dark');
        localStorage.setItem('theme', nowDark ? 'dark' : 'light');
        document.getElementById('icon-sun').classList.toggle('hidden', !nowDark);
        document.getElementById('icon-moon').classList.toggle('hidden', nowDark);

    });


    const btn = document.getElementById('user-menu-btn');
    const menu = document.getElementById('user-menu');

    btn.addEventListener('click', function () {
        menu.classList.toggle('hidden');
    });
});
