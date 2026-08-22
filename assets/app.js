import './stimulus_bootstrap.js';
import './password_manager_ignore.js';
import './nav_origin.js';
import './motion.js';
import './confirm.js';
/*
 * The stylesheets are NOT imported here, and their absence is deliberate.
 *
 * AssetMapper implements `import './x.css'` by mapping the entry to a
 * `data:application/javascript,` module that appends a <link> at runtime. That
 * works, and it costs two things. The cheap one is a flash of unstyled content:
 * the CSS is not discoverable in the HTML, so the browser cannot start fetching
 * it until it has parsed and run the module graph.
 *
 * The expensive one is that it makes a real Content-Security-Policy
 * impossible. Those data: URLs are scripts as far as the browser is concerned,
 * so `script-src 'self' 'nonce-…'` blocks every stylesheet in the application,
 * and the only way to allow them is `script-src … data:` — which readmits one
 * of the oldest XSS vectors there is and gives most of the directive's value
 * away. That is not a trade worth making for a mail client whose whole reason
 * to have a CSP is that it renders other people's HTML.
 *
 * So both stylesheets are <link>ed from templates/_layout/app.html.twig
 * instead, which is what the AssetMapper documentation recommends for CSS
 * anyway. See App\Security\Csp\ContentSecurityPolicyListener.
 */
