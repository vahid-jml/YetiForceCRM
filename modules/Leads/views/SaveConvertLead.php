<?php
/* +***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * Contributor(s): YetiForce.com
 * *********************************************************************************** */
vimport('~include/Webservices/ConvertLead.php');

class Leads_SaveConvertLead_View extends Vtiger_View_Controller
{

	function checkPermission(Vtiger_Request $request)
	{
		$moduleName = $request->getModule();
		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);

		$currentUserPriviligesModel = Users_Privileges_Model::getCurrentUserPrivilegesModel();
		if (!$currentUserPriviligesModel->hasModuleActionPermission($moduleModel->getId(), 'ConvertLead')) {
			throw new \Exception\NoPermitted('LBL_PERMISSION_DENIED');
		}

		$recordPermission = Users_Privileges_Model::isPermitted($moduleName, 'Save', $recordId);
		if (!$recordPermission) {
			throw new \Exception\NoPermittedToRecord('LBL_NO_PERMISSIONS_FOR_THE_RECORD');
		}
		
		$recordId = $request->get('record');
		$recordModel = Vtiger_Record_Model::getInstanceById($recordId);
		if (!Leads_Module_Model::checkIfAllowedToConvert($recordModel->get('leadstatus'))) {
			throw new \Exception\NoPermitted('LBL_PERMISSION_DENIED');
		}
	}

	public function preProcess(Vtiger_Request $request)
	{
		
	}

	public function process(Vtiger_Request $request)
	{
		$recordId = $request->get('record');
		$modules = $request->get('modules');
		$assignId = $request->get('assigned_user_id');
		$currentUser = Users_Record_Model::getCurrentUserModel();

		$entityValues = [];
		$entityValues['transferRelatedRecordsTo'] = $request->get('transferModule');
		$entityValues['assignedTo'] = vtws_getWebserviceEntityId(vtws_getOwnerType($assignId), $assignId);
		$entityValues['leadId'] = vtws_getWebserviceEntityId($request->getModule(), $recordId);
		$createAlways = Vtiger_Processes_Model::getConfig('marketing', 'conversion', 'create_always');

		$recordModel = Vtiger_Record_Model::getInstanceById($recordId, $request->getModule());
		$convertLeadFields = $recordModel->getConvertLeadFields();
		$availableModules = array('Accounts', 'Contacts');
		foreach ($availableModules as $module) {
			if (\includes\Modules::isModuleActive($module) && in_array($module, $modules)) {
				$entityValues['entities'][$module]['create'] = true;
				$entityValues['entities'][$module]['name'] = $module;

				foreach ($convertLeadFields[$module] as $fieldModel) {
					$fieldName = $fieldModel->getName();
					$fieldValue = $request->get($fieldName);

					//Potential Amount Field value converting into DB format
					if ($fieldModel->getFieldDataType() === 'currency') {
						$fieldValue = Vtiger_Currency_UIType::convertToDBFormat($fieldValue);
					} elseif ($fieldModel->getFieldDataType() === 'date') {
						$fieldValue = DateTimeField::convertToDBFormat($fieldValue);
					} elseif ($fieldModel->getFieldDataType() === 'reference' && $fieldValue) {
						$ids = vtws_getIdComponents($fieldValue);
						if (count($ids) === 1) {
							$fieldValue = vtws_getWebserviceEntityId(getSalesEntityType($fieldValue), $fieldValue);
						}
					}
					$entityValues['entities'][$module][$fieldName] = $fieldValue;
				}
			}
		}
		try {
			$results = true;
			if ($createAlways === true || $createAlways === 'true') {
				$leadModel = Vtiger_Module_Model::getCleanInstance($request->getModule());
				$results = $leadModel->searchAccountsToConvert($recordModel);
				$entityValues['entities']['Accounts']['convert_to_id'] = $results;
			}
			if (!$results) {
				$message = vtranslate('LBL_TOO_MANY_ACCOUNTS_TO_CONVERT', $request->getModule(), '');
				if ($currentUser->isAdminUser()) {
					$message = vtranslate('LBL_TOO_MANY_ACCOUNTS_TO_CONVERT', $request->getModule(), '<a href="index.php?module=MarketingProcesses&view=Index&parent=Settings"><span class="glyphicon glyphicon-folder-open"></span></a>');
				}
				$this->showError($request, '', $message);
				exit;
			}
		} catch (Exception $e) {
			$this->showError($request, $e);
			exit;
		}
		try {
			$result = vtws_convertlead($entityValues, $currentUser);
		} catch (Exception $e) {
			$this->showError($request, $e);
			exit;
		}

		if (!empty($result['Accounts'])) {
			$accountIdComponents = vtws_getIdComponents($result['Accounts']);
			$accountId = $accountIdComponents[1];
		}
		if (!empty($result['Contacts'])) {
			$contactIdComponents = vtws_getIdComponents($result['Contacts']);
			$contactId = $contactIdComponents[1];
		}

		if (!empty($accountId)) {
			$mappingFields = $recordModel->get('mappingFields');
			if (isset($mappingFields['Accounts']['shownerid'])) {
				$leadShownerField = Vtiger_Field_Model::getInstance('shownerid', $recordModel->getModule());
				$accRecordModel = Vtiger_Record_Model::getInstanceById($accountId, 'Accounts');
				$accRecordModel->set('shownerid', $leadShownerField->getUITypeModel()->getEditViewDisplayValue('', $recordId));
				Users_Privileges_Model::setSharedOwner($accRecordModel);
			}
			ModTracker_Record_Model::addConvertToAccountRelation('Accounts', $accountId, $assignId);
			header("Location: index.php?view=Detail&module=Accounts&record=$accountId");
		} elseif (!empty($contactId)) {
			header("Location: index.php?view=Detail&module=Contacts&record=$contactId");
		} else {
			$this->showError($request);
			exit;
		}
	}

	function showError($request, $exception = false, $message = '')
	{
		$viewer = $this->getViewer($request);
		$moduleName = $request->getModule();
		$currentUser = Users_Record_Model::getCurrentUserModel();

		if ($exception != false) {
			$viewer->assign('EXCEPTION', vtranslate($exception->getMessage(), $moduleName));
		} elseif ($message) {
			$viewer->assign('EXCEPTION', $message);
		}

		$viewer->assign('CURRENT_USER', $currentUser);
		$viewer->assign('MODULE', $moduleName);
		$viewer->view('ConvertLeadError.tpl', $moduleName);
	}

	public function validateRequest(Vtiger_Request $request)
	{
		$request->validateWriteAccess();
	}
}
