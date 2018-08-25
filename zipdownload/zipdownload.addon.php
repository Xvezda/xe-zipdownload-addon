<?php
/* Copyright (C) Xvezda <https://xvezda.com> */

if (!defined('__XE__')) exit();

// read libraries
require_once(_XE_PATH_ . 'addons/zipdownload/zipdownload.func.php');

$default_template_path = _XE_PATH_ . 'addons/zipdownload/tpl/default.html';
// addon admin setup
if ($called_position == 'after_module_proc'
        && Context::get('module') == 'admin'
        && $this->act == 'dispAddonAdminSetup'
        && Context::get('selected_addon') == 'zipdownload') {
    $logged_info = Context::get('logged_info');
    if ($logged_info->is_admin == 'Y') {
        $addon_info = Context::get('addon_info');
        foreach ($addon_info->extra_vars as $var) {
            if ($var->name == 'link_html' && $var->value == '') {
                $var->value = trim(
                    htmlspecialchars(
                        FileHandler::readFile($default_template_path),
                            ENT_COMPAT | ENT_HTML401, 'UTF-8', FALSE
                    )
                );
                break;
            }
        }
        Context::set('addon_info', $addon_info);

        // XE select box CSS hack
        Context::addHtmlFooter(
            '<style> #auto_insert, #require_point { vertical-align: top }</style>' . PHP_EOL
        );
    }
}

// download link insertion
if ($called_position == 'after_module_proc'
        && $this->act == 'dispBoardContent'
        && Context::getResponseMethod() == 'HTML') {
    $oAddonModel = getAdminModel('addon');
    $addon_info = $oAddonModel->getAddonInfoXml('zipdownload');

    $vars = new stdClass();
    foreach ($addon_info->extra_vars as $var) {
        $vars->{$var->name} = $var->value;
    }
    $oDocument = Context::get('oDocument');
    $download_url = sprintf('?document_srl=%s&amp;act=%s',
                                $oDocument->document_srl, 'zip');
    $link_html = !$vars->link_html ? FileHandler::readFile(
                                        $default_template_path
                                     )
                                     : $vars->link_html;

    $oFileModel = getModel('file');
    $files = $oFileModel->getFiles($oDocument->document_srl);
    if (count($files) > 1) {
        if ($vars->auto_insert != 'N') {
            $object = new stdClass();
            $object->direct_download = 'Y';
            $object->isvalid = 'Y';
            $object->source_filename = $link_html;
            $object->download_url = $download_url;
            $object->file_size = array_sum(array_map(function ($file) {
                return $file->file_size;
            }, $files));
            $object->download_count = (int) max(array_map(function ($file) {
                return $file->download_count;
            }, $files));

            if ($vars->insert_position != 'last') {
                array_unshift($files, $object);
            } else {
                array_push($files, $object);
            }
            $oDocument->uploadedFiles['file_srl'] = $files;
            Context::set('oDocument', $oDocument);
        } else {
            Context::set('zipdownload_link',
                sprintf('<a href="%s%s">%s</a>', getUrl(''), $download_url,
                    $link_html
                )
            );
        }
    }
}

// download process
if ($called_position == 'after_module_proc' && Context::get('act') == 'zip') {
    $target_srl = Context::get('document_srl');
    if (!$target_srl) {
        return $this->stop('msg_not_founded');
    }

    $oFileModel = getModel('file');
    $files = $oFileModel->getFiles($oDocument->document_srl);

    $logged_info = Context::get('logged_info');
    $is_logged = Context::get('is_logged');

    // check permissions
    if (isset($this->grant->access) && $this->grant->access !== true) {
        return $this->stop('msg_not_permitted');
    }

    $oDocument = Context::get('oDocument');
    if (!$oDocument->isExists()) {
        return $this->stop('msg_not_founded');
    }

    $file = $files[0]; // use "foreach ($files as $file)" instead for check all
    $file_module_config = $oFileModel->getFileModuleConfig($file->module_srl);
    if ($file_module_config->allow_outlink != 'Y') {
        $referer = parse_url($_SERVER['HTTP_REFERER']);
        if (!$file_module_config->allow_outlink_site) {
            return $this->stop('msg_not_allowed_outlink');
        } elseif ($referer['host'] != $_SERVER['HTTP_HOST']) {
            $sites = explode('\n', $file_module_config->allow_outlink_site);
            foreach ($sites as $site) {
                $url = parse_url(trim($site));
                if ($url['host'] != $referer['host']) {
                    return $this->stop('msg_not_allowed_outlink');
                }
            }
        }
    }
    if ($file->isvalid != 'Y') {
        return $this->stop('msg_not_permitted');
    }

    $grant_count = 0;
    foreach ($file_module_config->download_grant as $value) {
        if ($value) {
            $grant_count++;
        }
    }
    $oMemberModel = getModel('member');
    $member_groups = $oMemberModel->getMemberGroups($logged_info->member_srl,
                       $this->module_info->site_srl);
    if (Context::get('is_logged')) {
        if ($logged_info->is_admin != 'Y' && $grant_count) {
            $permission_count = count($file_module_config->download_grant);
            for ($idx = 0; $idx < $permission_count; $idx++) {
                $group_srl = $file_module_config->download_grant[$idx];
                if (!$member_groups[$group_srl]) {
                    return $this->stop('msg_not_permitted_download');
                }
            }
        }
    } elseif ($grant_count) {
        return $this->stop('msg_not_permitted_download');
    }

    //point check
    $oAddonModel = getAdminModel('addon');
    $addon_info = $oAddonModel->getAddonInfoXml('zipdownload');

    $vars = new stdClass();
    foreach ($addon_info->extra_vars as $var) {
        $vars->{$var->name} = $var->value;
    }

    $point = 0;
    $require_point = 0;
    $file_count = count($files);
    $member_srl = $logged_info->member_srl;
    $module_srl = $this->module_srl;

    $oModuleModel = getModel('module');
    $config = $oModuleModel->getModuleConfig('point');
    $module_config = $oModuleModel->getModulePartConfig('point',
            $module_srl);
    /*
    $file_count = 0;
    foreach ($files as $file) {
        if ($file->direct_download == 'N') {
            $file_count++;
        }
    }
    */

    if (abs($file->member_srl) != $member_srl) {
        if (isset($module_config['download_file'])) {
            $point = intval($module_config['download_file']);
        } else {
            $point = intval($config->download_file);
        }
        
        if (!$is_logged && ($config->disable_download == 'Y') && $point) {
            return $this->stop('msg_not_permitted_download');
        }

        $oPointModel = getModel('point');
        $cur_point = $oPointModel->getPoint($member_srl, true);

        $require_point = $point * $file_count;
        if (isset($vars->require_point) && $vars->require_point != '') {
            $require_point = intval($vars->require_point);
        }
        if ($config->disable_download == 'Y'
            && $require_point + $cur_point < 0) {
            return $this->stop('msg_cannot_download');
        }
    }
    zipDownload($target_srl, $require_point);
}

/* End of file zipdownload.addon.php */
/* Location: ./addons/zipdownload/zipdownload.addon.php */
