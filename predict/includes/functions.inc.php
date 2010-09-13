<?php

/*
 * Functions for the CUSF landing predictor software
 * Jon Sowman 2010
 */

require_once("config.inc.php");

function createModel($post_array) {
    $pred_model = array();

    // Populate the prediction model
    $pred_model['hour'] = (int)$post_array['hour']; //adjust for GMT
    $pred_model['min'] = (int)$post_array['min'];

    // Account for no 'seconds' value, though really it should be sent
    $pred_model['sec'] = (isset($post_array['sec']) ? 
        (int)$post_array['sec'] : 0);

    $pred_model['month'] = (int)$post_array['month'];
    $pred_model['day'] = (int)$post_array['day'];
    $pred_model['year'] = (int)$post_array['year'];

    $pred_model['lat'] = $post_array['lat'];
    $pred_model['lon'] = $post_array['lon'];
    $pred_model['asc'] = (float)$post_array['ascent'];
    $pred_model['alt'] = $post_array['initial_alt'];
    $pred_model['des'] = (float)$post_array['drag'];
    $pred_model['burst'] = $post_array['burst'];

    $pred_model['delta_lat'] = $post_array['delta_lat'];
    $pred_model['delta_lon'] = $post_array['delta_lon'];

    $pred_model['wind_error'] = 0;

    $pred_model['software'] = $post_array['software'];

    // make a timestamp of the form data
    $pred_model['timestamp'] = mktime($pred_model['hour'], $pred_model['min'], $pred_model['sec'], (int)$pred_model['month'], $pred_model['day'], (int)$pred_model['year'] - 2000);

    // and check that it's within range
    if ( $pred_model['timestamp'] > (time() + 180*3600) || $pred_model['timestamp'] < time() ) {
        return false;
        break;
    }

    return $pred_model;
}

function makesha1hash($pred_model) {
    $sha1str = "";
    foreach ( $pred_model as $idx => $value ){
        $sha1str .= $idx . "=" . $value . ",";
    }
    $uuid = sha1($sha1str);
    return $uuid;
}

function verifyModel($pred_model, $software_available) {
    if(!isset($pred_model)) return false;
    foreach($pred_model as $idx => $value) {
        if ($idx == "software") {
            if (!in_array($value, $software_available)) return false;
        } else if ($idx == "delta_lat" || $idx == "delta_lon") {
            if ( $value < 1 || $value > 10 ) return false;
        } else if (!is_numeric($value)) {
            return false;
        }
    }
    return true;
}

function runPred($pred_model) {
    // check if this is a re-run
    if ( !file_exists(PREDS_PATH . $pred_model['uuid'] . "/" . SCENARIO_FILE) ) {
        // if not, make a new directory and scenario file
        makePredDir($pred_model) or die ("Couldn't create the scenario dir");
        makeINI($pred_model);
    }

    // if we're using GFS HD, then append --hd to the exec string
    if ( $pred_model['software'] == "gfs_hd" ) $use_hd ="--hd ";
    else $use_hd = "";

    $predictor_lat = number_format($pred_model['lat'], 0);
    $predictor_lon = number_format($pred_model['lon'], 0);

    // use `at` to automatically background the task
    $ph = popen("at now", "w");
    $sh = "./predict.py -v --latdelta="
        .$pred_model['delta_lat']." --londelta=".$pred_model['delta_lon']
        ." -p1 -f5 -t ".$pred_model['timestamp']
        ." --lat=".$predictor_lat." --lon=".$predictor_lon." " . $use_hd
        . $pred_model['uuid'];
    if (DEBUG) shell_exec("echo " . $sh . " > " . AT_LOG);
    fwrite($ph, "cd " . ROOT . " && " . $sh );
    fclose($ph);

}

function makePredDir($pred_model) {
    //make sure we use the uuid from model
    if ( mkdir( PREDS_PATH . $pred_model['uuid'] ) ) {
        return true;
    } else {
        return false;
    }
}

function makeINI($pred_model) { // makes an ini file
    $fh = fopen(PREDS_PATH . $pred_model['uuid'] . "/" . SCENARIO_FILE, "w"); //write

    $w_string = "[launch-site]\nlatitude = " . $pred_model['lat'] . "\naltitude = " . $pred_model['alt'] . "\n";
    $w_string .= "longitude = " . $pred_model['lon'] . "\n[atmosphere]\nwind-error = ";
    $w_string .= $pred_model['wind_error'] . "\n[altitude-model]\nascent-rate = " . $pred_model['asc'] . "\n";
    $w_string .= "descent-rate  = " . $pred_model['des'] . "\nburst-altitude = ";
    $w_string .= $pred_model['burst'] . "\n[launch-time]\nhour = " . $pred_model['hour'] . "\n";
    $w_string .= "month = " . $pred_model['month'] . "\nsecond = " . $pred_model['sec'] . "\n";
    $w_string .= "year = " . $pred_model['year'] . "\nday = " . $pred_model['day'] . "\nminute = ";
    $w_string .= $pred_model['min'] . "\n";
    // add our predictor stuff
    $w_string .= "[predictor]\nlat-delta = " . $pred_model['delta_lat'] . "\n";
    $w_string .= "lon-delta = " . $pred_model['delta_lon'] . "\nsoftware = ";
    $w_string .= $pred_model['software'] . "\n";

    fwrite($fh, $w_string);
    fclose($fh);
}

function getModelByUUID($uuid) {
    if ( file_exists( PREDS_PATH . $uuid . "/" . SCENARIO_FILE ) ) {
        $pred_model = parse_ini_file(PREDS_PATH . $uuid . "/" . SCENARIO_FILE);
        return $pred_model;
    } else {
        return false;
    }
}

?>
