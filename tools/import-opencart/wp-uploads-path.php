<?php
/** Prints the uploads basedir, for shell scripts that need to place symlinks. */
$oc_uploads = wp_get_upload_dir();
echo $oc_uploads['basedir'], PHP_EOL;
