<?php
// SOLENT STREAMING VIDEO FILTER SETTINGS PAGE

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {

	
  // SOLENT AMENDMENTS
    $settings->add(new admin_setting_heading('solentmediaformats', get_string('settingsheading','filter_solentvideo'),  get_string('settingsinfo','filter_solentvideo')));

    $settings->add(new admin_setting_configcheckbox('filter_solentvideo_enable_solentmp4', get_string('mp4enable','filter_solentvideo'), get_string('mp4enableinfo','filter_solentvideo'), 1));	
	
	$settings->add(new admin_setting_configcheckbox('filter_solentvideo_enable_flowplayer', get_string('flowplayerenable','filter_solentvideo'), get_string('flowplayerenableinfo','filter_solentvideo'), 0));	
	
	$settings->add(new admin_setting_configcheckbox('filter_solentvideo_enable_jwplayer', get_string('jwplayerenable','filter_solentvideo'), get_string('jwplayerenableinfo','filter_solentvideo'), 0));
	

}
