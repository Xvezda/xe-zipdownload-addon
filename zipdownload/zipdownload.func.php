<?php
/* Copyright (C) Xvezda <https://xvezda.com> */

if (!defined('__XE__')) exit();

// Read class file
// ! https://gist.github.com/sunghwan2789/9b86467b6b99cf7934b5
require_once(_XE_PATH_ . 'addons/zipdownload/lib/directzip.class.php');

function zipDownload($target_srl, $point=0) {
    $oDocumentModel = getModel('document');
    $oDocument = $oDocumentModel->getDocument($target_srl);

    $oFileModel = getModel('file');
    $files = $oFileModel->getFiles($target_srl);

    $isImageprocessInstalled = class_exists('imageprocess');
    $isFileLogInstalled = class_exists('file_log');

    $logged_info = Context::get('logged_info');

    $orig_files = [];
    if ($isImageprocessInstalled) {
        try {
            $member_srl = $logged_info->member_srl;
            $oModuleModel = getModel('module');
            $oImageprocessModel = getModel('imageprocess');
            $oImageprocessConfig = $oModuleModel->getModuleConfig('imageprocess');
            $down_group = explode(';', $oImageprocessConfig->down_group);

            foreach ($files as $file) {
                $ofile = $oImageprocessModel->checkOfile($file->uploaded_filename,
                                                $oImageprocessConfig->store_path);
                if (file_exists($ofile)) {
                    $args = new stdClass();
                    $args->member_srl = $member_srl;
                    $args->down_group = $down_group;
                    if ($oImageprocessModel->getGrantDown($args)) {
                        $file->uploaded_filename = $ofile;
                        $file->file_size = filesize($ofile);
                    }
                }
                array_push($orig_files, $file);
            }
        }
        catch(Exception $e) { /* IGNORE Exception */ }
    }

    $zip = new DirectZip();
    $zip->open((($oDocument->variables['title'] == null) 
                    ? 'NoTitle'
                    : $oDocument->variables['title']) . '.zip');

    foreach ($files as $file) {
        // Call 'before' trigger
        if (!$isImageprocessInstalled && !$isFileLogInstalled) {
            // bypass if imageprocess module installed
            $output = ModuleHandler::triggerCall('file.downloadFile', 'before',
                $file);
        } elseif ($isFileLogInstalled) {
            // file_log module compatible
            try
            {
                $oFileLogController = getController('file_log');
                $oFileLogController->triggerFileDownloadBefore($file);
                // force trigger
            }
            catch(Exception $e) { /* IGNORE Exception */ }
        }

        $args = new stdClass();
        $args->file_srl = $file->file_srl;
        executeQuery('file.updateFileDownloadCount', $args);
        // Call 'after' trigger
        if (!$point && !$isFileLogInstalled) {
            $output = ModuleHandler::triggerCall('file.downloadFile', 'after',
            $file);
        } elseif ($isFileLogInstalled) {
            try
            {
                $oFileLogController = getController('file_log');
                $oFileLogController->triggerFileDownloadAfter($file);
                // force trigger
            }
            catch(Exception $e) { /* IGNORE Exception */ }
        }
    }

    if ($point) {
        $require_point = $point;
        $member_srl = $logged_info->member_srl;

        $oPointModel = getModel('point');
        $oPointController = getController('point');
        $cur_point = $oPointModel->getPoint($member_srl, true);
        $oPointController->setPoint($member_srl, $cur_point += $require_point);
    }

    if ($isImageprocessInstalled && count($orig_files) > 0) {
        foreach ($orig_files as $file) {
            $zip->addFile((substr($file->uploaded_filename, 0, 1) === '.'
                            ? _XE_PATH_
                            : '')
            . $file->uploaded_filename, $file->source_filename);
        }
    } else {
        foreach ($files as $file) {
            $zip->addFile(_XE_PATH_
            . $file->uploaded_filename, $file->source_filename);
        }
    }
    $zip->close();
    Context::close();
    exit();
}

/* End of file zipdownload.func.php */
/* Location: ./addons/zipdownload/zipdownload.func.php */
