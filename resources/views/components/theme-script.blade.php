{{--
    Applies the saved theme before the first paint, so pages never flash the
    wrong colours. Must stay inline in <head>, above the stylesheet.
    Choice: localStorage "theme" (light | dark); without one, the device setting.
    The toggle itself is handled in resources/js/app.js.
--}}
<script>
    (function () {
        var stored = null;
        try { stored = localStorage.getItem('theme'); } catch (e) {}
        var dark = stored === 'dark' || (stored !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', dark ? '#0f172a' : '#172a46'); }
    })();
</script>
