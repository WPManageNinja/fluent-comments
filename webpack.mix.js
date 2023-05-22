let mix = require('laravel-mix');

require('laravel-mix-svelte');

mix.js('resources/js/app.js', 'dist/js')
    .sass('resources/sass/app.scss', 'dist/css')
    .svelte({
        dev: false
    });

mix.js('resources/js/native-comments.js', 'dist/js');

mix.disableNotifications();
