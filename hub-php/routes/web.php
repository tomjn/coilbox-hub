<?php

// One file per area under routes/web/, so parallel work does not collide.
foreach (glob(__DIR__.'/web/*.php') as $file) {
    require $file;
}
