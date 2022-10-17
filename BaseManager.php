<?php
abstract class BaseManager
{
	protected $variablesArray;
	protected $authenticationKey;
	protected $password;
	protected $apiUrl;
	protected $apiFields;
	protected $emailNotify;
	protected $siteId;
	protected $tuffFilePath;
	protected $tuffFileHeader;
	protected $returnedData;
	protected $fileContents;
	protected $error 	= 0;
	protected $message 	= "";

	/**
	 * Class constructor
	 *
	 * @param
	 * @example (Some of the default params that are sent)
	 * @author Sam Sayegh
	 *
	 */
	public function __construct($variablesArray)
	{
		$this->setVariablesArray($variablesArray);
		$this->setAccessToken();
		$this->setAccessTokenCreationTimeStamp();
		$this->setRefreshToken();
		$this->setUrl();
		$this->setClientCode();
		$this->setChildClientCode();
		$this->setTuffType();
		$this->setEmailNotify();
		$this->setSiteId();
		$this->setSiteData();
	}

	protected function setVariablesArray($variablesArray)
	{
		$this->variablesArray = $variablesArray;
	}

	private function setAccessToken()
	{
		$this->accessToken = $this->variablesArray['accessToken'];
	}

	private function setAccessTokenCreationTimeStamp()
	{
		$this->accessTokenCreationTimeStamp = $this->variablesArray['accessTokenCreationTimeStamp'];
	}

	private function setRefreshToken()
	{
		$this->refreshToken = $this->variablesArray['refreshToken'];
	}

	private function setUrl()
	{
		$this->url = isset( $this->variablesArray['url'] ) ? $this->variablesArray['url'] : "https://test.com/rest/";
	}

	private function setClientCode()
	{
		$this->clientCode = isset( $this->variablesArray['client_code'] ) ? $this->variablesArray['client_code'] : "";
	}

	private function setChildClientCode()
	{
		$this->childClientCode = isset( $this->variablesArray['child_client_code'] ) ? $this->variablesArray['child_client_code'] : "";
	}

	private function setTuffType()
	{
		$this->tuffType = isset( $this->variablesArray['tuff_type'] ) ? $this->variablesArray['tuff_type'] : 'employees';
	}

	private function setEmailNotify()
	{
		$this->emailNotify = isset( $this->variablesArray['client_email_notify'] ) ? '' : '';
	}

	private function setSiteId()
	{
		$this->siteId = $this->variablesArray['site_id'];
	}

	private function setSiteData()
	{
		$dbConnectionManager	= new DatabaseManager();
		$siteDataQuery			= "SELECT * FROM site WHERE site_id=:site_id";
		$siteDataArray			= $dbConnectionManager->selectPdoQuery( $siteDataQuery, [':site_id' => $this->siteId]);

		if( count( $siteDataArray ) == 0 ){
			$this->returnedData	= [
				"success"	=> "false",
				"message"	=> "Site not found",
			];
			return $this->returnDataToVendor();
		}

		$siteDataArray	= $siteDataArray[0];
		$this->siteData = $siteDataArray;
	}

	protected function setTuffFilePath()
	{
		$this->tuffFilePath = '/var/www/api/test/tuff_files/' . $this->siteData['site_name'] . '/' . $this->tuffType . '/tuff_' . date('Y-m-d') . '.csv';
		$directory			= dirname( $this->tuffFilePath );
		if( !is_dir( $directory ) ){
			mkdir( $directory, 0755, true );
		}
		return $this->tuffFilePath;
	}

	protected function setTuffFileHeader()
	{
		$tuffFileHeaderArray = [];
		foreach( $this->dataArray as $count => $data )
		{
			$tuffFileHeaderArray = array_keys($data);
			break;
		}
		$this->tuffFileHeader = implode( ',', $tuffFileHeaderArray ) . "\n";
	}

	protected function buildTuffFile()
	{
		$file 					= fopen($this->tuffFilePath, 'w');
		$tuffFileHeaderArray 	= [];
		foreach( $this->dataArray as $id => $singleData )
		{
			$tuffFileHeaderArray = array_keys($singleData);
			break;
		}
		fputcsv($file, $tuffFileHeaderArray);

		foreach( $this->dataArray as $id => $singleData )
		{
			fputcsv($file, $singleData);
		}
		fclose($file);
	}

	protected function replaceCharacters( $string )
	{
		//$string = str_replace([''], [''], $string);
		$string = trim($string);
		$string = '"' . $string . '"';
		return $string;
	}

	protected function validateInput( $string )
	{
		if( preg_match('/[^a-z0-9 _]+/i', $string) )
		{
			$string = preg_replace('/[^A-Za-z0-9\-]/', '', $string);
		}
		return $string;
	}

	protected function setEmployeesTuffType()
	{
		$dbConnection		= mysqli_connect($this->siteData['site_dbserver'], $this->siteData['site_dbuser'], $this->siteData['site_dbpassword'], $this->siteData['site_dbname']);
		$queryResult		= mysqli_query($dbConnection, "SELECT setting_value FROM setting JOIN setting_value ON setting.setting_id = setting_value.setting_id WHERE setting_name ='employee_tuff_type'");
		$row				= mysqli_fetch_assoc($queryResult);
		$employeesTuffType	= isset( $row['setting_value'] ) && !empty( $row['setting_value'] ) ? $row['setting_value'] : 'pre_historical_tuff';
		$this->employeesTuffType = $employeesTuffType;
	}

	protected function uploadTuffFile()
	{
		$siteUrl			= $this->siteData['site_url'];
		$dbConnection		= mysqli_connect($this->siteData['site_dbserver'], $this->siteData['site_dbuser'], $this->siteData['site_dbpassword'], $this->siteData['site_dbname']);
		$queryResult		= mysqli_query($dbConnection, "SELECT setting_value FROM setting JOIN setting_value ON setting.setting_id = setting_value.setting_id WHERE setting_name ='api_auth_token'");
		$row				= mysqli_fetch_assoc($queryResult);
		$tuffApiAuthToken	= $row['setting_value'];
		

		//Build The Tuff File
		$this->setTuffFilePath();
		$this->buildTuffFile();
		$this->setEmployeesTuffType();

		$apiUrl = $this->employeesTuffType == 'historical_tuff' ? $siteUrl . '/api/upload/employees-csv-sftp' : $siteUrl . '/api/upload/users-tuff-csv-form';

		$postData = [
						'csvfile'=> curl_file_create($this->tuffFilePath),
						'token'=>$tuffApiAuthToken,
						'skipDistance'=>true,
						'checkHeaders'=>'true',
					];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $apiUrl);
		curl_setopt($ch, CURLOPT_POST,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec ($ch);
		curl_close ($ch);

		$uploadResult = json_decode($result, true);
		if( $uploadResult['success'] == false )
		{
			$this->sendErrorMail(implode("<br>", $uploadResult['errors']));
			return ["success"=>"false", "message"=>json_encode( $uploadResult['errors'] )];
		}

		unlink($this->tuffFilePath);
		$this->sendSuccessMail();
		return ["success"=>"true", "message"=>"Tuff File Successfully Uploaded"];
	}

	protected function uploadCandidatesTuffFile()
	{
		$siteUrl			= $this->siteData['site_url'];
		$dbConnection		= mysqli_connect($this->siteData['site_dbserver'], $this->siteData['site_dbuser'], $this->siteData['site_dbpassword'], $this->siteData['site_dbname']);
		$queryResult		= mysqli_query($dbConnection, "SELECT setting_value FROM setting JOIN setting_value ON setting.setting_id = setting_value.setting_id WHERE setting_name ='api_auth_token'");
		$row				= mysqli_fetch_assoc($queryResult);
		$tuffApiAuthToken	= $row['setting_value'];

		//Build The Tuff File
		$this->setTuffFilePath();
		$this->buildTuffFile();

		$postData = [
						'csvfile'=> curl_file_create($this->tuffFilePath),
						'token'=>$tuffApiAuthToken,
						'skipDistance'=>true,
						'checkHeaders'=>'true',
					];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $siteUrl . '/api/upload/candidates-csv-sftp');
		curl_setopt($ch, CURLOPT_POST,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec ($ch);
		curl_close ($ch);

		$uploadResult = json_decode($result, true);
		if( $uploadResult['success'] == false )
		{
			$this->sendErrorMail(implode("<br>", $uploadResult['errors']));
			return ["success"=>"false", "message"=>json_encode( $uploadResult['errors'] )];
		}

		unlink($this->tuffFilePath);
		$this->sendSuccessMail();
		return ["success"=>"true", "message"=>"Tuff File Successfully Uploaded"];
	}

	protected function uploadRequisitionsTuffFile()
	{
		$siteUrl			= $this->siteData['site_url'];
		$dbConnection		= mysqli_connect($this->siteData['site_dbserver'], $this->siteData['site_dbuser'], $this->siteData['site_dbpassword'], $this->siteData['site_dbname']);
		$queryResult		= mysqli_query($dbConnection, "SELECT setting_value FROM setting JOIN setting_value ON setting.setting_id = setting_value.setting_id WHERE setting_name ='api_auth_token'");
		$row				= mysqli_fetch_assoc($queryResult);
		$tuffApiAuthToken	= $row['setting_value'];

		//Build The Tuff File
		$this->setTuffFilePath();
		$this->buildTuffFile();

		$postData = [
						'csvfile'=> curl_file_create($this->tuffFilePath),
						'token'=>$tuffApiAuthToken,
						'skipDistance'=>true,
						'checkHeaders'=>'true',
					];
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $siteUrl . '/api/upload/requisitions-csv-sftp');
		curl_setopt($ch, CURLOPT_POST,1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		$result = curl_exec ($ch);
		curl_close ($ch);

		$uploadResult = json_decode($result, true);
		if( $uploadResult['success'] == false )
		{
			$this->sendErrorMail(implode("<br>", $uploadResult['errors']));
			return ["success"=>"false", "message"=>json_encode( $uploadResult['errors'] )];
		}

		unlink($this->tuffFilePath);
		$this->sendSuccessMail();
		return ["success"=>"true", "message"=>"Tuff File Successfully Uploaded"];
	}

	private function handleReturnedDataToVendor( $response )
	{
		$response = json_encode( $response );
		return $response;
	}

	protected function returnDataToVendor()
	{
		return $this->handleReturnedDataToVendor( $this->returnedData );
	}

	protected function sendErrorMail( $error='' )
	{
		$to		 = !empty( $this->emailNotify ) ? $this->emailNotify : 'test@test.com;';
		$subject = $this->siteData['site_name'] . ' Test -- Error Uploading ' . ucfirst($this->tuffType) . ' TUFF';
		$message = 'There was an error while uploading ' . ucfirst($this->tuffType) . ' TUFF file to <b>' . $this->siteData['site_name'] . '</b>.' . "<br>" . $this->siteData['site_url'] . "<br>";
		$message .= "<br>" . 'Error:' . "<br>";
		$message .= $error;
		$headers = 'From: testScript@test.com' . "\r\n";
		$headers .= 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-Type: text/html; charset=ISO-8859-1' . "\r\n";
		$headers .= "Bcc: " . 'test@test.com;' . "\r\n";
		$to = str_replace(";", ",", $to);
		mail($to, $subject, $message, $headers);
		return true;
	}

	protected function sendSuccessMail()
	{
		$to		 = !empty( $this->emailNotify ) ? $this->emailNotify : 'test@test.com;';
		$subject = $this->siteData['site_name'] . ' Test -- ' . ucfirst($this->tuffType) . ' TUFF Successfully Uploaded';
		$message = 'Test -- ' . ucfirst($this->tuffType) . ' TUFF file was successfully uploaded to <b>' . $this->siteData['site_name'] . '</b>.' . "<br>" . $this->siteData['site_url'] . "<br>";
		$headers = 'From: testScript@testSite.com' . "\r\n";
		$headers .= 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-Type: text/html; charset=ISO-8859-1' . "\r\n";
		$headers .= "Bcc: " . 'test@test.com;' . "\r\n";
		$to = str_replace(";", ",", $to);
		mail($to, $subject, $message, $headers);
		return true;
	}

	private function getAccesstoken()
	{
		$this->accessToken = 'none';
		$this->accessTokenCreationTimeStamp = 'none';
		$this->refreshToken = 'none';
		$this->variablesArray['accessToken'] = $this->accessToken;
		$this->variablesArray['accessTokenCreationTimeStamp'] = $this->accessTokenCreationTimeStamp;
		$this->variablesArray['refreshToken'] = $this->refreshToken;
		$url = isset($this->variablesArray['url']) ? $this->variablesArray['url'] : "https://test.com/rest/";
		$bearerToken = base64_encode($this->variablesArray['client_id'] . ':' . $this->variablesArray['client_secret']);
		$postData = 'grant_type=' . $this->variablesArray['client_grant_type'];
		$curl = curl_init($url.'api/token');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			'Authorization: Basic ' . $bearerToken,
			'Accept: x-www-form-urlencoded',
			));
		curl_setopt( $curl, CURLOPT_TIMEOUT, 30 );
		curl_setopt( $curl, CURLOPT_POST, 1 );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $postData );
		$jsondata	= curl_exec($curl);
		$status		= curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$curlError	= curl_error($curl);
		curl_close($curl);
		$accessTokenData = json_decode( $jsondata, true );
		if( isset($accessTokenData) && isset($accessTokenData['access_token']) ){
			$this->accessToken = $accessTokenData['access_token'];
			$this->refreshToken = $accessTokenData['refresh_token'];
			$this->accessTokenCreationTimeStamp = strtotime($accessTokenData['.issued']);
			$this->variablesArray['accessToken'] = $this->accessToken;
			$this->variablesArray['accessTokenCreationTimeStamp'] = $this->accessTokenCreationTimeStamp;
			$this->variablesArray['refreshToken'] = $this->refreshToken;
		}
	}

	protected function sendCurlGetRequest( $url )
	{
		$currentGMTDate = strtotime(gmdate('Y-m-d H:i:s') . " UTC");
		if( $currentGMTDate - $this->accessTokenCreationTimeStamp > 250 ){
			$this->getAccesstoken();
		}
		$headers = [
						"Content-Type: application/json; charset=\"UTF-8\"",
						"Content-Length: 0",
						"Accept: application/json",
						'Authorization: Bearer ' . $this->accessToken,
					];

		$curlProcess = curl_init();
		curl_setopt( $curlProcess, CURLOPT_URL, $url );
		curl_setopt( $curlProcess, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $curlProcess, CURLOPT_HEADER, 0 );
		curl_setopt( $curlProcess, CURLOPT_TIMEOUT, 90 );
		curl_setopt( $curlProcess, CURLOPT_POST, 0 );
		curl_setopt( $curlProcess, CURLOPT_RETURNTRANSFER, TRUE );
		$response = curl_exec( $curlProcess );
		$errorMsg 		= "";
		$curlErrorNum 	= "";
		if ($curlErrorNum = curl_errno($curlProcess)) {
			$errorMsg = curl_error($curlProcess);
		}
		$status	= curl_getinfo($curlProcess, CURLINFO_HTTP_CODE);
		curl_close( $curlProcess );
		if( $status != '200' || $curlErrorNum != "" || $errorMsg != ""){
			$errorMsg = $errorMsg == "" ? "Curl Error " . $status : $errorMsg;
			return ["success"=>"false", "message"=>$errorMsg];
		}
		return $response;
	}

	abstract function syncData();

	/**
	 * Class Destructor
	 *
	 */
	function __destruct()
	{

	}
}
