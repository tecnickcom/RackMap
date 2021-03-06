<?php
//============================================================+
// File name   : tce_import_getos.php
// Begin       : 2013-01-08
// Last Update : 2013-01-14
//
// Description : Import object data from getos.sh data file.
//
// Author: Nicola Asuni
//
// (c) Copyright:
//               Fubra Limited
//               Manor Coach House
//               Church Hill
//               Aldershot
//               Hampshire
//               GU12 4RQ
//               http://www.rackmap.net
//               support@rackmap.net
//
// License:
//    Copyright (C) 2013-2013 Fubra Limited
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU Affero General Public License as
//    published by the Free Software Foundation, either version 3 of the
//    License, or (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU Affero General Public License for more details.
//
//    You should have received a copy of the GNU Affero General Public License
//    along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
//    See LICENSE.TXT file for more information.
//============================================================+

/**
 * @file
 * Import object data from getos.sh data file.
 * @package net.rackmap.admin
 * @author Nicola Asuni
 * @since 2013-01-08
 */

/**
 */

require_once('../config/tce_config.php');

$pagelevel = K_AUTH_IMPORT_GETOS;
require_once('../../shared/code/tce_authorization.php');

$thispage_title = $l['t_getos_importer'];
require_once('../code/tce_page_header.php');
require_once('../../shared/code/tce_functions_form.php');

switch($menu_mode) {

	case 'upload': {
		if ($_FILES['userfile']['name']) {
			require_once('../code/tce_functions_upload.php');
			require_once('tce_parseGetosData.php');
			// upload file
			$uploadedfile = F_upload_file('userfile', K_PATH_CACHE);
			if ($uploadedfile !== false) {
				$datafile = K_PATH_CACHE.$uploadedfile;
				// decode data
				$dataobj = new parseGetosData($datafile);
				// delete uploaded file
				unlink($datafile);
				// get array of data
				$data = $dataobj->getArray();
				// for each server
				foreach ($data as $srv) {
					if (F_importServerObj($srv) === false) {
						F_print_error('ERROR', $srv['ip']);
					}
				} // end for each server
			} // end of uploaded file
		}
		break;
	}

	default: {
		break;
	}

} //end of switch
?>

<div class="container">

<div class="tceformbox">
<form action="<?php echo $_SERVER['SCRIPT_NAME']; ?>" method="post" enctype="multipart/form-data" id="form_import">

<div class="row">
<span class="label">
<label for="userfile"><?php echo $l['w_upload_file']; ?></label>
</span>
<span class="formw">
<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo K_MAX_UPLOAD_SIZE ?>" />
<input type="file" name="userfile" id="userfile" size="20" title="<?php echo $l['h_upload_file']; ?>" />
</span>
&nbsp;
</div>

<div class="row">
<?php
// show buttons by case
F_submit_button("upload", $l['w_upload'], $l['h_submit_file']);
?>
</div>

</form>

</div>
<?php

echo '<div class="pagehelp">'.$l['hp_import_getos'].'</div>'.K_NEWLINE;
echo '</div>'.K_NEWLINE;

require_once('../code/tce_page_footer.php');

// -----------------------------------------------------------------------------

/**
 * Import the specifed server object.
 * @param $srv (array) array containing object data.
 * @return true in case of success, false otherwise
 */
function F_importServerObj($srv) {
	global $l, $db;
	require_once('../config/tce_config.php');
	
	if (!isset($srv['serial']) OR empty($srv['serial'])) {
		F_print_error('ERROR', 'missing serial');
		return false;
	}
	// get ID of the object with the same serial number
	$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.' WHERE obj_tag=\''.F_escape_sql($srv['serial']).'\' LIMIT 1';
	if ($r = F_db_query($sql, $db)) {
		if ($m = F_db_fetch_assoc($r)) {
			$obj_id = $m['obj_id'];
		} else {
			// this object do not exist.
			F_print_error('ERROR', $srv['serial']);
			return false;
		}
	} else {
		F_display_db_error(false);
		return false;
	}
	
	// attribute map
	$srvattrmap = array(
		'hostname' => 66, 
		'os release' => 68, 
		'os type' => 67, 
		'kernel name' => 69, 
		'kernel release' => 70, 
		'kernel version' => 71, 
		'kernel architecture' => 72, 
		'product' => 17, 
		'uuid' => 26
	);

	// for each attribute
	foreach ($srvattrmap as $k => $v) {
		if (isset($srv[$k]) AND (strlen($srv[$k]) > 0)) {
			$value = $srv[$k];
			if (($k == 'product') AND isset($srv['manufacturer']) AND !empty($srv['manufacturer'])) {
				$value = $srv['manufacturer'].' '.$value;
			}
			// add or update attribute value
			$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
				atv_obj_id,
				atv_atb_id,
				atv_value
				) VALUES (
				'.$obj_id.',
				'.$v.',
				\''.F_escape_sql($value).'\'
				)';
			if (!$ra = F_db_query($sqla, $db)) {
				F_display_db_error(false);
				return false;
			}
		}
	}

	// cpu attribute map
	$cpuattrmap = array(
		'Socket Designation' => 92,
		'Family' => 94,
		'ID' => 93,
		'Architecture' => 56,
		'CPU op-mode(s)' => 83,
		'Byte Order' => 84,
		'Thread(s) per core' => 85,
		'Core(s) per socket' => 55,
		'Vendor ID' => 86,
		'CPU family' => 87,
		'Model' => 88,
		'Stepping' => 89,
		'CPU MHz' => 25,
		'Virtualization' => 90,
		'L1d cache' => 81,
		'L1i cache' => 82,
		'L1 cache' => 57,
		'L2 cache' => 58,
		'L3 cache' => 59
	);

	// cpu
	if (isset($srv['dmi']['Processor Information']) AND !empty($srv['dmi']['Processor Information'])) {
		$cpucount = 0;
		foreach($srv['dmi']['Processor Information'] as $cpu) {
			++$cpucount;
			$cpuname = sprintf('CPU%02d', $cpucount);
			// check if CPU exist
			$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
				WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$obj_id.' AND obj_obt_id=58 AND obj_name=\''.$cpuname.'\'
				ORDER BY obj_name';
			if ($r = F_db_query($sql, $db)) {
				if ($m = F_db_fetch_assoc($r)) {
					// update existing object
					$cpu_obj_id = $m['obj_id'];
				} else {
					// create new object
					$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
						obj_obt_id,
						obj_name,
						obj_description,
						obj_label,
						obj_tag,
						obj_mnf_id,
						obj_owner_id,
						obj_tenant_id
						) VALUES (
						58,
						\''.$cpuname.'\',
						'.F_empty_to_null('').',
						'.F_empty_to_null('').',
						'.F_empty_to_null('').',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).'
						)';
					if (!$ro = F_db_query($sqlo, $db)) {
						F_display_db_error(false);
						return false;
					} else {
						$cpu_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
					}
					// set object map
					$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
						omp_parent_obj_id,
						omp_child_obj_id
						) VALUES (
						'.$obj_id.',
						'.$cpu_obj_id.'
						)';
					if (!$rm = F_db_query($sqlm, $db)) {
						F_display_db_error(false);
						return false;
					}
				}
				// for each attribute
				foreach ($cpuattrmap as $k => $v) {
					$value = '';
					if (isset($cpu[$k])) {
						$value = $cpu[$k];
					} elseif (isset($srv['cpu'][$k])) {
						$value = $srv['cpu'][$k];
					}
					if (preg_match('/([0-9\.]+)[\s]?([KMGT][B]?)/', $value, $vmtch) > 0) {
						$value = $vmtch[1];
					}
					if (strlen($value) > 0) {
						// add or update attribute value
						$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
							atv_obj_id,
							atv_atb_id,
							atv_value
							) VALUES (
							'.$cpu_obj_id.',
							'.$v.',
							\''.F_escape_sql($value).'\'
							)';
						if (!$ra = F_db_query($sqla, $db)) {
							F_display_db_error(false);
							return false;
						}
					}
				}
			} else {
				F_display_db_error(false);
				return false;
			}
		}
	}
	
	// memory attribute map
	$memattrmap = array(
		'Total Width' => 95,
		'Data Width' => 96,
		'Size' => 52,
		'Form Factor' => 97,
		'Locator' => 99,
		'Type' => 98,
		'Speed' => 61
		//'Manufacturer' => ,
		//'Serial Number' => ,
		//'Asset Tag' => ,
		//'Part Number' => ,
		//'Rank' => 
	);
	
	// memory
	if (isset($srv['ram']) AND !empty($srv['ram'])) {
		// get total ram in gigabytes
		$totalram = round(floatval($srv['ram']) / 1024 / 1024 / 1024);
			// check if RAM object exist
			$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
				WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$obj_id.' AND obj_obt_id=59
				ORDER BY obj_name';
			if ($r = F_db_query($sql, $db)) {
				if ($m = F_db_fetch_assoc($r)) {
					// update existing object
					$ram_obj_id = $m['obj_id'];
				} else {
					// create new object
					$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
						obj_obt_id,
						obj_name,
						obj_description,
						obj_label,
						obj_tag,
						obj_mnf_id,
						obj_owner_id,
						obj_tenant_id
						) VALUES (
						59,
						\'RAM\',
						'.F_empty_to_null('').',
						'.F_empty_to_null('').',
						'.F_empty_to_null('').',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).'
						)';
					if (!$ro = F_db_query($sqlo, $db)) {
						F_display_db_error(false);
						return false;
					} else {
						$ram_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
					}
					// set object map
					$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
						omp_parent_obj_id,
						omp_child_obj_id
						) VALUES (
						'.$obj_id.',
						'.$ram_obj_id.'
						)';
					if (!$rm = F_db_query($sqlm, $db)) {
						F_display_db_error(false);
						return false;
					}
				}
				// add or update attribute value
				$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
					atv_obj_id,
					atv_atb_id,
					atv_value
					) VALUES (
					'.$ram_obj_id.',
					60,
					\''.F_escape_sql($totalram).'\'
					)';
				if (!$ra = F_db_query($sqla, $db)) {
					F_display_db_error(false);
					return false;
				}
			} else {
				F_display_db_error(false);
				return false;
			}
			// - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
		
			if (isset($srv['dmi']['Memory Device']) AND !empty($srv['dmi']['Memory Device'])) {
			$memcount = 0;
			foreach($srv['dmi']['Memory Device'] as $mem) {
				++$memcount;
				$memname = sprintf('SLOT%02d', $memcount);
				// check if object exist
				$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
					WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$ram_obj_id.' AND obj_obt_id=60 AND obj_name=\''.$memname.'\'
					ORDER BY obj_name';
				if ($r = F_db_query($sql, $db)) {
					if ($m = F_db_fetch_assoc($r)) {
						// update existing memory slot object
						$mem_obj_id = $m['obj_id'];
					} else {
						// create new object
						$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
							obj_obt_id,
							obj_name,
							obj_description,
							obj_label,
							obj_tag,
							obj_mnf_id,
							obj_owner_id,
							obj_tenant_id
							) VALUES (
							60,
							\''.$memname.'\',
							'.F_empty_to_null('').',
							'.F_empty_to_null('').',
							'.F_empty_to_null('').',
							'.F_zero_to_null(0).',
							'.F_zero_to_null(0).',
							'.F_zero_to_null(0).'
							)';
						if (!$ro = F_db_query($sqlo, $db)) {
							F_display_db_error(false);
							return false;
						} else {
							$mem_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
						}
						// set object map
						$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
							omp_parent_obj_id,
							omp_child_obj_id
							) VALUES (
							'.$ram_obj_id.',
							'.$mem_obj_id.'
							)';
						if (!$rm = F_db_query($sqlm, $db)) {
							F_display_db_error(false);
							return false;
						}
					}
					// for each memory attribute
					foreach ($memattrmap as $k => $v) {
						$value = '';
						if (isset($mem[$k])) {
							$value = $mem[$k];
							if (($k == 'Size') OR ($k == 'Speed')) {
								$value = intval($value);
							}
							// add or update attribute value
							$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
								atv_obj_id,
								atv_atb_id,
								atv_value
								) VALUES (
								'.$mem_obj_id.',
								'.$v.',
								\''.F_escape_sql($value).'\'
								)';
							if (!$ra = F_db_query($sqla, $db)) {
								F_display_db_error(false);
								return false;
							}
						}
					}
				} else {
					F_display_db_error(false);
					return false;
				}
			}
		}
	} // end srv['ram']

	// network attribute map
	$netattrmap = array(
		'device' => 74,
		'mac' => 9,
		'ipv4' => 10,
		'bcast' => 75,
		'mask' => 76,
		'ipv6' => 73,
		'encap' => 77,
		'scope' => 78,
		'mtu' => 79,
		'metric' => 80
	);

	// network
	if (isset($srv['network']) AND !empty($srv['network'])) {
		$netcount = 0;
		foreach($srv['network'] as $net) {
			if (preg_match('/^eth[0-9]+$/', $net['device']) > 0) {
				++$netcount;
				$netname = sprintf('ETH%02d', $netcount);
				// check if device exist
				$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
					WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$obj_id.' AND obj_obt_id=37 AND obj_name=\''.$netname.'\'
					ORDER BY obj_name';
				if ($r = F_db_query($sql, $db)) {
					if ($m = F_db_fetch_assoc($r)) {
						// update existing object
						$net_obj_id = $m['obj_id'];
					} else {
						// create new object
						$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
							obj_obt_id,
							obj_name,
							obj_description,
							obj_label,
							obj_tag,
							obj_mnf_id,
							obj_owner_id,
							obj_tenant_id
							) VALUES (
							37,
							\''.$netname.'\',
							'.F_empty_to_null('').',
							'.F_empty_to_null('').',
							'.F_empty_to_null('').',
							'.F_zero_to_null(0).',
							'.F_zero_to_null(0).',
							'.F_zero_to_null(0).'
							)';
						if (!$ro = F_db_query($sqlo, $db)) {
							F_display_db_error(false);
							return false;
						} else {
							$net_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
						}
						// set object map
						$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
							omp_parent_obj_id,
							omp_child_obj_id
							) VALUES (
							'.$obj_id.',
							'.$net_obj_id.'
							)';
						if (!$rm = F_db_query($sqlm, $db)) {
							F_display_db_error(false);
							return false;
						}
					}
					// for each attribute
					foreach ($netattrmap as $k => $v) {
						$value = '';
						if (isset($net[$k])) {
							$value = $net[$k];
						} elseif (isset($srv['net'][$k])) {
							$value = $srv['net'][$k];
						}
						if (strlen($value) > 0) {
							// add or update attribute value
							$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
								atv_obj_id,
								atv_atb_id,
								atv_value
								) VALUES (
								'.$net_obj_id.',
								'.$v.',
								\''.F_escape_sql($value).'\'
								)';
							if (!$ra = F_db_query($sqla, $db)) {
								F_display_db_error(false);
								return false;
							}
						}
					}
				} else {
					F_display_db_error(false);
					return false;
				}
			}
		}
	}
	
	// disk controller (8) attribute map
	$ctrlattrmap = array(
		'Bus Interface' => 100,
		'Slot' => 101,
		'Cache Serial Number' => 102,
		'Hardware Revision' => 103,
		'Firmware Version' => 104,
		'Total Cache Size' => 105,
		'Total Cache Memory Available' => 106
	);
	
	// disk array (65) attribute map
	$darrattrmap = array(
		'Interface Type' => 122
	);

	// logical drive (66) attribute map
	$logdrvattrmap = array(
		'Size' => 123,
		'Fault Tolerance' => 107,
		'Heads' => 108,
		'Sectors Per Track' => 109,
		'Cylinders' => 110,
		'Strip Size' => 111,
		'Full Stripe Size' => 112,
		'Caching' =>  113,
		'Unique Identifier' => 114,
		'Disk Name' => 115,
		'Mount Points' => 116,
		'Logical Drive Label' => 117,
		'Drive Type' => 118
	);

	// physical drive (66) attribute map
	$phydrvattrmap = array(
		'Port' => 119,
		'Box' => 120,
		'Bay' => 121,
		'Drive Type' => 118,
		'Interface Type' => 122,
		'Size' => 123,
		'Rotational Speed' => 124,
		'Firmware Revision' => 104,
		'Model' => 125,
		'PHY Transfer Rate' => 126
	);
	
	$disknum = 0;
	$logdrvdisks = array(); // list physical disks that belongs to logical drives
	// hp disk controller data
	if (isset($srv['hpdisks']) AND !empty($srv['hpdisks'])) {
		$ctrlcount = 0;
		foreach($srv['hpdisks'] as $ctrl) {
			++$ctrlcount;
			$ctrlname = sprintf('DISKCTRL%02d', $ctrlcount);
			// check if device exist
			$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
				WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$obj_id.' AND obj_obt_id=8 AND obj_name=\''.$ctrlname.'\'
				ORDER BY obj_name';
			if ($r = F_db_query($sql, $db)) {
				if ($m = F_db_fetch_assoc($r)) {
					// update existing object
					$ctrl_obj_id = $m['obj_id'];
				} else {
					// create new object
					$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
						obj_obt_id,
						obj_name,
						obj_description,
						obj_label,
						obj_tag,
						obj_mnf_id,
						obj_owner_id,
						obj_tenant_id
						) VALUES (
						8,
						\''.$ctrlname.'\',
						'.F_empty_to_null($ctrl['item']).',
						'.F_empty_to_null('').',
						'.F_empty_to_null($ctrl['Serial Number']).',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).',
						'.F_zero_to_null(0).'
						)';
					if (!$ro = F_db_query($sqlo, $db)) {
						F_display_db_error(false);
						return false;
					} else {
						$ctrl_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
					}
					// set object map
					$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
						omp_parent_obj_id,
						omp_child_obj_id
						) VALUES (
						'.$obj_id.',
						'.$ctrl_obj_id.'
						)';
					if (!$rm = F_db_query($sqlm, $db)) {
						F_display_db_error(false);
						return false;
					}
				}
				// for each attribute
				foreach ($ctrlattrmap as $k => $v) {
					$value = '';
					if (isset($ctrl[$k]) AND (strlen($ctrl[$k]) > 0)) {
						$value = $ctrl[$k];
						if (preg_match('/([0-9\.]+)[\s]?([KMGT]B|Gbps)/', $value, $vmtch) > 0) {
							$value = $vmtch[1];
						}
						// add or update attribute value
						$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
							atv_obj_id,
							atv_atb_id,
							atv_value
							) VALUES (
							'.$ctrl_obj_id.',
							'.$v.',
							\''.F_escape_sql($value).'\'
							)';
						if (!$ra = F_db_query($sqla, $db)) {
							F_display_db_error(false);
							return false;
						}
					}
				}
			} else {
				F_display_db_error(false);
				return false;
			}
			// - - - - - - - - - -
			// disk arrays
			foreach($ctrl as $ck => $darr) {
				if (is_array($darr) AND ($darr['item'] == 'Array')) {
					$diskarrayname = sprintf('ARRAY%02d', ($ck + 1));
					// check if device exist
					$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
						WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$ctrl_obj_id.' AND obj_obt_id=65 AND obj_name=\''.$diskarrayname.'\'
						ORDER BY obj_name';
					if ($r = F_db_query($sql, $db)) {
						if ($m = F_db_fetch_assoc($r)) {
							// update existing object
							$darr_obj_id = $m['obj_id'];
						} else {
							// create new object
							$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
								obj_obt_id,
								obj_name,
								obj_description,
								obj_label,
								obj_tag,
								obj_mnf_id,
								obj_owner_id,
								obj_tenant_id
								) VALUES (
								65,
								\''.$diskarrayname.'\',
								'.F_empty_to_null($darr['item']).',
								'.F_empty_to_null('').',
								'.F_empty_to_null($darr['value']).',
								'.F_zero_to_null(0).',
								'.F_zero_to_null(0).',
								'.F_zero_to_null(0).'
								)';
							if (!$ro = F_db_query($sqlo, $db)) {
								F_display_db_error(false);
								return false;
							} else {
								$darr_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
							}
							// set object map
							$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
								omp_parent_obj_id,
								omp_child_obj_id
								) VALUES (
								'.$ctrl_obj_id.',
								'.$darr_obj_id.'
								)';
							if (!$rm = F_db_query($sqlm, $db)) {
								F_display_db_error(false);
								return false;
							}
						}
						// for each attribute
						foreach ($darrattrmap as $k => $v) {
							$value = '';
							if (isset($darr[$k]) AND (strlen($darr[$k]) > 0)) {
								$value = $darr[$k];
								// add or update attribute value
								$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
									atv_obj_id,
									atv_atb_id,
									atv_value
									) VALUES (
									'.$darr_obj_id.',
									'.$v.',
									\''.F_escape_sql($value).'\'
									)';
								if (!$ra = F_db_query($sqla, $db)) {
									F_display_db_error(false);
									return false;
								}
							}
						}
					} else {
						F_display_db_error(false);
						return false;
					}
					// - - - - - - - - - -
					// logical (66) and physical (61) disks
					foreach($darr as $dk => $dsk) {
						if (is_array($dsk)) {
							if ($dsk['item'] == 'Logical Drive') {
								$ldiskname = sprintf('LOGICALDRIVE%02d', ($dk + 1));
								// check if device exist
								$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
									WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$darr_obj_id.' AND obj_obt_id=66 AND obj_name=\''.$ldiskname.'\'
									ORDER BY obj_name';
								if ($r = F_db_query($sql, $db)) {
									if ($m = F_db_fetch_assoc($r)) {
										// update existing object
										$ldsk_obj_id = $m['obj_id'];
									} else {
										// create new object
										$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
											obj_obt_id,
											obj_name,
											obj_description,
											obj_label,
											obj_tag,
											obj_mnf_id,
											obj_owner_id,
											obj_tenant_id
											) VALUES (
											66,
											\''.$ldiskname.'\',
											'.F_empty_to_null($dsk['item']).',
											'.F_empty_to_null('').',
											'.F_empty_to_null($dsk['value']).',
											'.F_zero_to_null(0).',
											'.F_zero_to_null(0).',
											'.F_zero_to_null(0).'
											)';
										if (!$ro = F_db_query($sqlo, $db)) {
											F_display_db_error(false);
											return false;
										} else {
											$ldsk_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
										}
										// set object map
										$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
											omp_parent_obj_id,
											omp_child_obj_id
											) VALUES (
											'.$darr_obj_id.',
											'.$ldsk_obj_id.'
											)';
										if (!$rm = F_db_query($sqlm, $db)) {
											F_display_db_error(false);
											return false;
										}
									}
									// for each attribute
									foreach ($logdrvattrmap as $k => $v) {
										$value = '';
										if (isset($dsk[$k]) AND (strlen($dsk[$k]) > 0)) {
											$value = $dsk[$k];
											if (preg_match('/([0-9\.]+)[\s]?([KMGT]B|Gbps)/', $value, $vmtch) > 0) {
												$value = $vmtch[1];
											}
											// add or update attribute value
											$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
												atv_obj_id,
												atv_atb_id,
												atv_value
												) VALUES (
												'.$ldsk_obj_id.',
												'.$v.',
												\''.F_escape_sql($value).'\'
												)';
											if (!$ra = F_db_query($sqla, $db)) {
												F_display_db_error(false);
												return false;
											}
										}
									}
								} else {
									F_display_db_error(false);
									return false;
								}
								$logdrvdisks[$ldsk_obj_id] = array();
							} elseif ($dsk['item'] == 'physicaldrive') {
								++$disknum;
								$diskname = sprintf('DISK%02d', $disknum);
								$logdrvdisks[$ldsk_obj_id][] = $diskname;
								// check if device exist
								$sql = 'SELECT obj_id FROM '.K_TABLE_OBJECTS.', '.K_TABLE_OBJECTS_MAP.'
									WHERE obj_id=omp_child_obj_id AND omp_parent_obj_id='.$ctrl_obj_id.' AND obj_obt_id=61 AND obj_name=\''.$diskname.'\'
									ORDER BY obj_name';
								if ($r = F_db_query($sql, $db)) {
									if ($m = F_db_fetch_assoc($r)) {
										// update existing object
										$pdsk_obj_id = $m['obj_id'];
									} else {
										// create new object
										$sqlo = 'INSERT INTO '.K_TABLE_OBJECTS.' (
											obj_obt_id,
											obj_name,
											obj_description,
											obj_label,
											obj_tag,
											obj_mnf_id,
											obj_owner_id,
											obj_tenant_id
											) VALUES (
											61,
											\''.$diskname.'\',
											'.F_empty_to_null($dsk['item']).',
											'.F_empty_to_null('').',
											'.F_empty_to_null($dsk['value']).',
											'.F_zero_to_null(0).',
											'.F_zero_to_null(0).',
											'.F_zero_to_null(0).'
											)';
										if (!$ro = F_db_query($sqlo, $db)) {
											F_display_db_error(false);
											return false;
										} else {
											$pdsk_obj_id = F_db_insert_id($db, K_TABLE_OBJECTS, 'obj_id');
										}
										// set object map
										$sqlm = 'INSERT INTO '.K_TABLE_OBJECTS_MAP.' (
											omp_parent_obj_id,
											omp_child_obj_id
											) VALUES (
											'.$ctrl_obj_id.',
											'.$pdsk_obj_id.'
											)';
										if (!$rm = F_db_query($sqlm, $db)) {
											F_display_db_error(false);
											return false;
										}
									}
									// for each attribute
									foreach ($phydrvattrmap as $k => $v) {
										$value = '';
										if (isset($dsk[$k]) AND (strlen($dsk[$k]) > 0)) {
											$value = $dsk[$k];
											if (preg_match('/([0-9\.]+)[\s]?([KMGT]B|Gbps)/', $value, $vmtch) > 0) {
												$value = $vmtch[1];
											}
											// add or update attribute value
											$sqla = 'REPLACE INTO '.K_TABLE_ATTRIBUTE_VALUES.' (
												atv_obj_id,
												atv_atb_id,
												atv_value
												) VALUES (
												'.$pdsk_obj_id.',
												'.$v.',
												\''.F_escape_sql($value).'\'
												)';
											if (!$ra = F_db_query($sqla, $db)) {
												F_display_db_error(false);
												return false;
											}
										}
									}
								} else {
									F_display_db_error(false);
									return false;
								}
							}
						}
					} // end of disks
				} // is array
			} // end of disk array

		} // end for each controller
		
		// add physical disks on the logical drives
		if (isset($logdrvdisks) AND !empty($logdrvdisks)) {
			foreach ($logdrvdisks AS $logdrvid => $disks) {
				$sql = 'UPDATE '.K_TABLE_OBJECTS.' SET
					obj_description='.F_empty_to_null(implode(', ', $disks)).'
					WHERE obj_id='.$logdrvid.'';
				if (!$r = F_db_query($sql, $db)) {
					F_display_db_error(false);
				}
			}
		}

	}

	return true;
}

//============================================================+
// END OF FILE
//============================================================+
