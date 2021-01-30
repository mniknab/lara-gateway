<?php

namespace Larabookir\Gateway\Saman;

use Illuminate\Support\Facades\Input;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use SoapClient;

class Saman extends PortAbstract implements PortInterface {
	/**
	 * Address of main SOAP server
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://sep.shaparak.ir/payments/referencepayment.asmx?wsdl';

	protected $cellNumber = null;
	/**
	 * {@inheritdoc}
	 */
	public function set($amount) {
		$this->amount = $amount;

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ready() {
		$this->newTransaction();

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function redirect() {

		try {
			$curl = curl_init();

			curl_setopt_array($curl, [
				CURLOPT_URL => 'https://sep.shaparak.ir/MobilePG/MobilePayment',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'POST',
				CURLOPT_POSTFIELDS => [
					'Action' => 'token',
					'Amount' => $this->amount,
					'MID' => $this->config->get('gateway.saman.merchant'),
					'ResNum' => $this->transactionId(),
					'RedirectURL' => $this->getCallback(),
					'CellNumber' => $this->cellNumber,
				],
			]);

			$response = json_decode(curl_exec($curl));
			curl_close($curl);
		} catch (\Exception $e) {
			throw $e;
		}

		if ($response->status != 1) {
			throw new \Exception($response->errorDesc);
		}

		return \View::make('gateway::saman-redirector')->with([
			'token' => $response->token,
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction) {
		parent::verify($transaction);

		$this->userPayment();
		$this->verifyPayment();

		return $this;
	}

	/**
	 * Sets callback url
	 * @param $url
	 */
	function setCallback($url) {
		$this->callbackUrl = $url;
		return $this;
	}

	/**
	 * Gets callback url
	 * @return string
	 */
	function getCallback() {
		if (!$this->callbackUrl) {
			$this->callbackUrl = $this->config->get('gateway.saman.callback-url');
		}

		$url = $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);

		return $url;
	}

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws SamanException
	 */
	protected function userPayment() {
		$this->refId = Input::get('RefNum');
		$this->trackingCode = Input::get('‫‪TRACENO‬‬');
		$this->cardNumber = Input::get('‫‪SecurePan‬‬');
		$payRequestRes = Input::get('State');
		$payRequestResCode = Input::get('StateCode');

		if ($payRequestRes == 'OK') {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, @SamanException::$errors[$payRequestRes]);
		throw new SamanException($payRequestRes);
	}

	/**
	 * Verify user payment from bank server
	 *
	 * @return bool
	 *
	 * @throws SamanException
	 * @throws SoapFault
	 */
	protected function verifyPayment() {
		$fields = array(
			"merchantID" => $this->config->get('gateway.saman.merchant'),
			"RefNum" => $this->refId,
			"password" => $this->config->get('gateway.saman.password'),
		);

		try {
			$soap = new SoapClient($this->serverUrl);
			$response = $soap->VerifyTransaction($fields["RefNum"], $fields["merchantID"]);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		$response = intval($response);

		if ($response == $this->amount) {
			$this->transactionSucceed();
			return true;
		}

		//Reverse Transaction
		if ($response > 0) {
			try {
				$soap = new SoapClient($this->serverUrl);
				$response = $soap->ReverseTransaction($fields["RefNum"], $fields["merchantID"], $fields["password"], $response);

			} catch (\SoapFault $e) {
				$this->transactionFailed();
				$this->newLog('SoapFault', $e->getMessage());
				throw $e;
			}
		}

		//
		$this->transactionFailed();
		$this->newLog($response, SamanException::$errors[$response]);
		throw new SamanException($response);

	}

	public function setCellNumber($cellNumber) {
		$this->cellNumber = $cellNumber;
	}

}
