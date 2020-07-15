<?php
/**
 * @author Amadi Ifeanyi <amadiify.com>
 * @package Coral Request Sync manager
 **/
class Sync
{
	private $connection = null;
	private $post = [];

	/**
	 * @var array $noRequestMessage
	 **/
	public static $noRequestMessage = [
		'status' => 'error',
		'message' => 'Missing an endpoint. It appears that you did not request for an endpoint'
	];

	/**
	 * @method Sync postCheckAvaliability
	 * @return array
	 *
	 * This method checks for room avaliability
	 **/
	public function postCheckAvaliability()
	{
		$response = ['status' => 'error', 'message' => 'missing post data (inizioperiodo, fineperiodo, numpersone)'];

		// post data sent
		if ($this->post('has', 'inizioperiodo', 'fineperiodo', 'numpersone')) :

			// get output
			$output = $this->avaliabilityOutput();

			// response returned successfully
			if (strlen($output) > 1000) :

				// encode output
				$response['status'] = 'success';
				$response['message'] = base64_encode($output);

			else:

				// remove form tag
				$cleanOutput = preg_replace('/(<form)([\s\S]*form>)/', '', $output);

				// remove all tags
				$cleanOutput = trim(strip_tags($cleanOutput));

				// update message
				$response['message'] = $cleanOutput;

			endif;

		endif;

		// return array
		return $response;
	}

	/**
	 * @method Sync getTodayRates
	 * @return array
	 *
	 * This method gets the rate for current day
	 **/
	public function getTodayRates()
	{
		// build post
		$_POST['inizioperiodo'] = date('Y-m-d');
		$_POST['fineperiodo'] = date('Y-m-d', strtotime('tomorrow'));
		$_POST['numpersone'] = 1;

		// return array
		return $this->postDaysRates();
	}

	/**
	 * @method Sync postDaysRates
	 * @return array
	 *
	 * This method gets the rate for one or more days
	 **/
	public function postDaysRates()
	{
		// post data sent
		if ($this->post('has', 'inizioperiodo', 'fineperiodo', 'numpersone')) :

			// get output
			$output = $this->avaliabilityOutput();

			// get rate table
			$rateTableEndPosition = strrpos($output, '<!--rate table ends here-->');

			// where it stops
			if ($rateTableEndPosition !== false) $output = substr($output, 0, $rateTableEndPosition);

			// parse table
			$dom = new DOMDocument();
			$dom->loadHTML($output);

			// find table
			$tableRow = $dom->getElementsByTagName('tr');

			// response 
			$response = [
				'rate' => [],
				'deposit' => [],
				'price' => []
			];

			foreach ($tableRow as $index => $row) :

				if ($index > 2) :

					// add rate
					if (stripos($row->textContent, 'rate') !== false) :

						// get rate and price
						list($rate, $price) = explode(':', $row->textContent);

						// clean price
						$price = rtrim(preg_replace('/[^0-9\.]/', '', $price), '.');

						// clean rate
						$rate = str_ireplace('rate', '', $rate);

						// remove quotes
						$rate = trim(preg_replace('/["]/', '', $rate));

						// add rate and price
						$response['rate'][] = $rate;
						$response['price'][] = $price;

					endif;

				    // add desposit
					if (stripos($row->textContent, 'deposit') !== false) :

						// get the deposit
						$deposit = explode(':', $row->textContent);

						// get the end
						$deposit = end($deposit);

						// trim off anything that is not number or (.)
						$deposit = preg_replace('/[^0-9\.]/', '', $deposit);


						// add deposit
						$response['deposit'][] = $deposit;

					endif;	

				endif;

			endforeach;

			// return array
			return ['status' => 'success', 'rates' => $response];

		endif;

		// error
		return ['status' => 'error', 'message' => 'missing post data (inizioperiodo, fineperiodo, numpersone)'];
	}
	
	public function postReservation()
	{
		if ($this->post('has', 'cognome', 'nome', 'inizioperiodo1', 'fineperiodo1', 'nometipotariffa1', 'numpersone1'))
		{
			// Set globals
			$GLOBALS['id_utente'] = 1;
			$GLOBALS['lingua_mex'] = 'en';
			$_POST['origine'] = 'prenota.php';
			$_POST['inseriscicliente'] = 1;
			$inserire_dati_cliente = 'SI';

			$pag = "prenota.php";
			$titolo = "Carol Residents Hotel Management Software";
						
			//$inserire = true;
			$exampleData = include('example_post.php');
			
			// merge example data
			$exampleData = array_merge($exampleData, $_POST);
			
			// merge post data 
			$_POST = $exampleData;

			// ask for quick update
			if (!defined('API_RESERVATION')) define('API_RESERVATION', true);
				
			// 
			include_once 'prenota.php';

			
		}
			
	}

	public function updateTransactionInfo($tabletransazioniweb, $id_sessione, $numconnessione)
	{
		$versione_transazione = prendi_numero_versione($tabletransazioniweb,"idtransazioni","anno");
		$adesso = date("YmdHis");
		list($usec, $sec) = explode(' ', microtime());
		mt_srand((float) $sec + ((float) $usec * 100000));
		$val_casuale = mt_rand(100000,999999);
		$id_transazione = $adesso.$val_casuale.$versione_transazione . '31';

		$id_sessione = '2020062116133550962514330';

		$year = date('Y');
		$_POST['id_transazione'] = $id_transazione;
		$_POST['anno'] = $year;
		$_POST['id_sessione'] = $id_sessione;
		$time = date('Y-m-d g:i:s');
		$rate = $_POST['nometipotariffa1'];

		// try to get the clientid
		$lastname = ucfirst($_POST['cognome']);
		$firstname = ucfirst($_POST['nome']);

		// make post public
		$this->post = $_POST;
		$this->connection = $numconnessione;

		// check if account exists
		$query = $numconnessione->query("select * from clienti where cognome = '{$lastname}' and nome = '{$firstname}'");

		// insert reservation
		if ($query !== false && $query->num_rows > 0) : $this->insertReservation($query); die; endif;

	}

	public function insertReservation($query=null)
	{

		// try to get the clientid
		$lastname = ucfirst($this->post['cognome']);
		$firstname = ucfirst($this->post['nome']);
		$numconnessione = $this->connection;

		// run query
		$query = $query === null ? $numconnessione->query("select * from clienti where cognome = '{$lastname}' and nome = '{$firstname}'") : $query;

		// get clientid
		$row = $query->fetch_assoc();
		$clientid = $row['idclienti'];

		// insert reservation
		$table = 'prenota' . date('Y');
		$prenotaClienti = 'rclientiprenota' . date('Y');

		// get motivazione
		$regole = $numconnessione->query('select motivazione from regole'.date('Y') . ' where tariffa_per_app = "'.$this->post['nometipotariffa1'].'"');

		// motivazione
		$motivazione = $regole->num_rows > 0 ? $regole->fetch_assoc()['motivazione'] : null;

		// inserted by
		$utente_inserimento = $row['utente_inserimento'];

		// get today date
		$today = date('Y-m-d', $this->post['inizioperiodo1']);
		$checkOut = date('Y-m-d', $this->post['fineperiodo1']);

		// get the rate for today
		$todayRate = $numconnessione->query('select * from periodi'.date('Y') . ' where datainizio = "'.$today.'" limit 0,1');

		// get the checkout period
		$checkoutP = $numconnessione->query('select * from periodi'.date('Y') . ' where datainizio = "'.$checkOut.'" limit 0,1');

		// today rate
		$record = $todayRate->num_rows > 0 ? $todayRate->fetch_assoc() : null;
		$rate = $record !== null ? $record[$this->post['nometipotariffa1']] : 0;

		// get checkin
		$checkin = $record !== null ? $record['idperiodi'] : 0;
		$checkout = $checkoutP->num_rows > 0 ? $checkoutP->fetch_assoc()['idperiodi'] : 0;


		// get total fee. multiply by nights
		$total = intval($rate) * intval($this->post['nights']);

		// repeat nights
		$tariffesettimanali = str_repeat($rate . ',', intval($this->post['nights']));

		// remove leading comma
		$tariffesettimanali = rtrim($tariffesettimanali, ',');

		// insert_id;
		$room = $this->post['room'];
		// random letters
		$letters = str_shuffle('abcdefghijklmnopqrstuvwxyz');
		$letter = substr($letters, 0, 4);

		// payment confirmed 
		$confirmed = $this->post['paid'] == 1 ? 'S' : 'N';
		$amountPaid = $confirmed == 'S' ? $total : 0;

		// full date
		$fulldate = $this->post['date_added'];

		// occupants
		$occupants = $this->post['numpersone1'];

		// pick a random room
		$rooms = explode(',', $motivazione);
		$randid = array_rand($rooms);
		$random_room = $rooms[$randid];

		// get the last inserted record
		$records = $numconnessione->query('select idprenota from '. $table . ' order by idprenota desc limit 0, 1');

		// get idprenota
		$idprenota = $records->num_rows > 0 ? (intval($records->fetch_assoc()['idprenota']) + 1) : 1;


		// prenota data
		$prenotaSQL = "INSERT INTO {$table} (`idprenota`, `idclienti`, `idappartamenti`, `iddatainizio`, `iddatafine`, `assegnazioneapp`, `app_assegnabili`, `num_persone`, `cat_persone`, `idprenota_compagna`, `tariffa`, `tariffesettimanali`, `incompatibilita`, `sconto`, `tariffa_tot`, `caparra`, `commissioni`, `tasseperc`, `pagato`, `metodo_pagamento`, `codice`, `origine`, `commento`, `conferma`, `checkin`, `checkout`, `id_anni_prec`, `datainserimento`, `hostinserimento`, `data_modifica`, `utente_inserimento`) VALUES
({$idprenota}, {$clientid}, '{$random_room}', {$checkin}, {$checkout}, 'c', '{$motivazione}', {$occupants},  NULL, NULL, '{$room}#@&{$total}', '{$tariffesettimanali}', NULL, NULL, {$total}, 6200, NULL, NULL, {$amountPaid}, NULL, '{$letter}', NULL, NULL, '{$confirmed}', NULL, NULL, NULL, '{$fulldate}', 'localhost', NULL, {$utente_inserimento});
";

		// insert and get insert id
		$query = $numconnessione->query($prenotaSQL);

		// did it insert ?
		if ($query) :

			$rclientiprenotaSQL = "INSERT INTO {$prenotaClienti} (`idprenota`,`idclienti`,`num_ordine`,`parentela`,`datainserimento`,`hostinserimento`,`utente_inserimento`) VALUES ({$idprenota}, {$clientid},1,'','{$fulldate}','coralresidencehotel.com', {$utente_inserimento})";

			// insert now
			$numconnessione->query($rclientiprenotaSQL);

		endif;
	}

	/**
	 * @method Sync post
	 * @return mixed
	 *
	 * This is an helper method
	 **/
	private function post(string $method)
	{
		// get arguments
		$arguments = func_get_args();

		// start from index 1
		$arguments = array_splice($arguments, 1);

		// using switch state
		switch ($method) :

			// check if post has data
			case 'has':

				// @var int $has
				$has = 0;

				// check now
				foreach ($arguments as $name) :

					// check if post data is set
					if (isset($_POST[$name])) $has++;

				endforeach;

				// all good ?
				return ($has == count($arguments)) ? true : false;


		endswitch;
	}

	/**
	 * @method Sync interpolateBody
	 * @return mixed
	 *
	 * This is an helper method
	 **/
	private function interpolateBody(string $body)
	{
		// get the begining
		$body = strstr($body, '<!--start interpolation-->');

		if ($body !== false) :

			// get the ending position
			$endingPosition = strpos($body, '<!--end interpolation-->');

			if ($endingPosition !== false) $body = substr($body, 0, $endingPosition);

		endif;


		// return string
		return $body;
	}

	/**
	 * @method Sync avaliabilityOutput
	 * @return string
	 *
	 * This is an helper method
	 **/
	private function avaliabilityOutput()
	{
		// start buffer
		ob_start();

		// Set globals
		$GLOBALS['id_utente'] = 2;
		$GLOBALS['lingua_mex'] = 'en';

		// include the needed file
		include_once 'disponibilita.php';

		// get output
		$output = $this->interpolateBody(ob_get_contents());

		// clean up
		ob_clean();

		// return string
		return $output;
	}

}

// set the response header
header('Content-Type: application/json');

// get the request uri sent from $_SERVER
$requestUri = $_SERVER['REQUEST_URI'];

// get the needle and it's size
$needle = 'sync.php/';

// The request lives somewhere after sync.php/, so we move our pointer after sync.php/
$pointer = strrpos($requestUri, $needle) + strlen($needle);

// get the request sent
$request = substr($requestUri, $pointer);

// if nothing was sent, we just stop here
if (strlen($request) == 0) : 

	// it failed here
	echo json_encode(Sync::$noRequestMessage, JSON_PRETTY_PRINT); 

	// stop execution
	return; 

endif; 

// create array from request
$requestArray = explode('/', $request);

// get the request method
$method = strtolower($_SERVER['REQUEST_METHOD']);

// the first request would be the method
$requestMethod = $requestArray[0];

// get arguments
$arguments = array_splice($requestArray, 1);

// request method may contain -, space, none acceptable characters. So lets fix that
$requestMethod = ucwords(preg_replace('/[^a-zA-Z\_]/', ' ', $requestMethod));

// next we remove spaces
$requestMethod = preg_replace('/[\s]+/', '', $requestMethod);

// now lets build our request
$request = $method . $requestMethod;

// get sync instance
$sync = new Sync;

// check if that exists
if (!method_exists($sync, $request)) :

	// oops! 
	echo json_encode(['status' => 'error', 'route' => $request, 'message' => 'not found'], JSON_PRETTY_PRINT); 

	// stop execution
	return;

endif;

// set environment
if (!defined('API_REQUEST')) define('API_REQUEST', true);

// load method
echo json_encode(call_user_func_array([$sync, $request], $arguments), JSON_PRETTY_PRINT);

