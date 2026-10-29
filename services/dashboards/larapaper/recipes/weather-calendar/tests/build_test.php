<?php

require_once __DIR__.'/../build.php';

$markup = buildMarkup(__DIR__.'/..');
check(
    'build_inlines_all_logic',
    ! str_contains($markup, 'logic:')
        && str_contains($markup, '$rainLogic = (function () {')
        && str_contains($markup, '$weatherLogic = (function () {')
        && str_contains($markup, '$warningsLogic = (function () {')
        && str_contains($markup, '$calendarLogic = (function () {')
        && str_contains($markup, '$iconsLogic = (function () {')
        && str_contains($markup, '$fitLogic = (function () {')
        && ! str_contains($markup, '<?php'),
    substr($markup, 0, 300)
);

$broken = sys_get_temp_dir().'/trmnl-build-test-'.getmypid();
mkdir("{$broken}/logic", 0777, true);
file_put_contents("{$broken}/layout.blade.php", "{{-- logic:nope --}}\n");
try {
    buildMarkup($broken);
    check('build_unknown_marker_throws', false, 'no exception');
} catch (RuntimeException) {
    check('build_unknown_marker_throws', true);
} finally {
    unlink("{$broken}/layout.blade.php");
    rmdir("{$broken}/logic");
    rmdir($broken);
}
