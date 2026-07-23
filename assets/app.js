import './stimulus_bootstrap.js';
import './styles/app.css';
import '@fortawesome/fontawesome-free/css/all.css';

document.addEventListener('turbo:load', function() {
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
