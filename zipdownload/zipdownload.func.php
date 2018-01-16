<?php
/* Copyright (C) Xvezda <https://xvezda.com> */

if (!defined('__XE__')) exit();

// Read class file
// ! https://gist.github.com/sunghwan2789/9b86467b6b99cf7934b5
require_once(_XE_PATH_ . 'addons/zipdownload/lib/directzip.class.php');

function zipDownload($target_srl) {
    $oDocumentModel = getModel('document');
    $oDocument = $oDocumentModel->getDocument($target_srl);

    $oFileModel = getModel('file');
    $files = $oFileModel->getFiles($target_srl);

    $zip = new DirectZip();
    $zip->open($oDocument->variables['title'] . '.zip');

    foreach ($files as $file) {
        // Call 'before' trigger
        $output = ModuleHandler::triggerCall('file.downloadFile', 'before',
            $file);

        $args = new stdClass();
        $args->file_srl = $file->file_srl;
        executeQuery('file.updateFileDownloadCount', $args);
        // Call 'after' trigger
        $output = ModuleHandler::triggerCall('file.downloadFile', 'after',
            $file);

        // Add file to zip
        $zip->addFile(_XE_PATH_
                    . $file->uploaded_filename, $file->source_filename);
    }
    $zip->close();
    Context::close();
    exit();
}

/* End of file zipdownload.func.php */
/* Location: ./addons/zipdownload/zipdownload.func.php */
