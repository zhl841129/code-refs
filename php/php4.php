<?php
/**
 * @file
 *
 * Xero APi trait for xero api call.
 */

namespace App\CustomTraits;

use XeroOAuth;
use App\Jobs\EmailSenderSimple;

include_once(base_path() . '/vendor/xero/xerooauth-php//tests/testRunner.php');

trait XeroApiTrait
{
    /**
     * get XERO API configuration.
     */
    public function getConfig()
    {
        return [
            'application_type'  => 'Private',
            'oauth_callback'    => 'foo',
            'user_agent'        => 'Visual Domain Invoicing',
            'consumer_key'      => env('XERO_APP_CONSUMER_KEY'),
            'shared_secret'     => env('XERO_APP_SECRET_KEY'),
            // API versions
            'core_version'      => '2.0',
            'payroll_version'   => '1.0',
            'file_version'      => '1.0',
            'rsa_private_key'   => env('XERO_RSA_PRIVATE_KEY_PATH'),
            'rsa_public_key'    => env('XERO_RSA_PUBLIC_KEY_PATH'),
        ];
    }

    /**
     * Make request to create invoices through API.
     *
     * @param string $requestXml xml request to create xero invoices.
     *
     * @return String xero json callback.
     */
    public function createXeroInvoicesBatch($requestXml)
    {
        return $this->callXero($requestXml, 'PUT');
    }


    /**
     * Make request to update invoice through API.
     *
     * @param string $requestXml xml request to update xero invoices.
     *
     * @return mixed api response or null and send email.
     */
    public function updateXeroInvoices($requestXml)
    {
        return $this->callXero($requestXml, 'POST');
    }

    /**
     * Make request to udpate invoice through API.
     *
     * @param string $requestXml xml request to update xero invoices.
     * @param String $method xero api call method.
     * @param String $returnFormat xero api call expected return format..
     *
     * @return mixed api response or null and send email.
     */
    private function callXero($requestXml, $method = "POST", $returnFormat = 'json')
    {
        $xero = new XeroOAuth($this->getConfig());
        $xero->_xero_curl_options['curl_timeout'] = 1800;   // time is in seconds.

        $initialCheck = $xero->diagnostics();

        if (count($initialCheck) == 0) {
            $session = persistSession([
                'oauth_token' => $xero->config['consumer_key'],
                'oauth_token_secret' => $xero->config['shared_secret'],
                'oauth_session_handle' => '',
            ]);

            $oauthSession = retrieveSession();

            if (isset($oauthSession['oauth_token'])) {
                $xero->config['access_token'] = $oauthSession['oauth_token'];
                $xero->config['access_token_secret'] = $oauthSession['oauth_token_secret'];

                // Show validation for all invoices.
                $url = $xero->url('Invoices', 'core');
                $response = $xero->request($method, $url, [], $requestXml, $returnFormat);
                return $response;
            }
        } else {
            $subject = "XERO invoicing error";
            $message = "There are error occur for xml request validation. \n" . $requestXml;

            $this->sendXeroInvoicingEmail($subject, $message);
        }
    }

    /**
     * Download xero invoice pdf file.
     *
     * @param string $invoiceId the invoice id from xero.
     */
    public function downloadXeroInvoicePdfFile($invoiceId)
    {
        $xero = new XeroOAuth($this->getConfig());
        $xero->_xero_curl_options['curl_timeout'] = 1800;   // time is in seconds.
        $initialCheck = $xero->diagnostics();

        if (count($initialCheck) == 0) {
            $session = persistSession([
                'oauth_token' => $xero->config['consumer_key'],
                'oauth_token_secret' => $xero->config['shared_secret'],
                'oauth_session_handle' => ''
            ]);
            $oauthSession = retrieveSession();
            if (isset($oauthSession ['oauth_token'])) {
                $xero->config ['access_token'] = $oauthSession ['oauth_token'];
                $xero->config ['access_token_secret'] = $oauthSession ['oauth_token_secret'];

                $url = 'https://api.xero.com/api.xro/2.0/invoices/' . $invoiceId;
                $response = $xero->request('GET', $url, [], null, 'pdf');

                header("Content-Disposition: attachment; filename=" . $invoiceId . ".pdf");

                echo $response['response'];
            }
        }
    }

    /**
     * Download xero invoice pdf to file server.
     *
     * @param String $xeroInvoiceIdentifier the string id from xero invoice.
     *
     * @return boolean $returnFLag TRUE if downloading successfully.
     */
    private function downloadXeroInvoicePdfToLocalServer($xeroInvoiceIdentifier)
    {
        try {
            $xero = new XeroOAuth($this->getConfig());
            $initialCheck = $xero->diagnostics();
            if (count($initialCheck) == 0) {
                $session = persistSession([
                    'oauth_token' => $xero->config ['consumer_key'],
                    'oauth_token_secret' => $xero->config ['shared_secret'],
                    'oauth_session_handle' => ''
                ]);
                $oauthSession = retrieveSession();
                if (isset($oauthSession ['oauth_token'])) {
                    $xero->config ['access_token'] = $oauthSession ['oauth_token'];
                    $xero->config ['access_token_secret'] = $oauthSession ['oauth_token_secret'];

                    $url = 'https://api.xero.com/api.xro/2.0/invoices/' . $xeroInvoiceIdentifier;
                    $response = $xero->request('GET', $url, [], null, 'pdf');
                    if ($response['code'] == 200) {
                        $fileSource = $response['response'];
                        $localFile = getInvoiceFileWithFullPath($xeroInvoiceIdentifier);
                        $fh = fopen($localFile, 'w') or die("can't open file");
                        fwrite($fh, $fileSource);
                        fclose($fh);
                    }
                }
            }
            $returnFLag = true;
        } catch (Exception $e) {
            $returnFLag = false;
        }
        return $returnFLag;
    }

    /**
     * Authorise invoice by api.
     *
     * @param String $xeroInvoiceIdentifier xero invoice string id.
     * @param String $xeroXmlString.
     *
     * @return mixed authorised xero invoice response or FALSE for error.
     */
    private function updateInvoice($xeroInvoiceIdentifier, $xeroXmlString)
    {
        if (isset($xeroInvoiceIdentifier)) {
            $xero = new XeroOAuth($this->getConfig());
            $initialCheck = $xero->diagnostics();
            if (count($initialCheck) == 0) {
                $session = persistSession([
                    'oauth_token' => $xero->config ['consumer_key'],
                    'oauth_token_secret' => $xero->config ['shared_secret'],
                    'oauth_session_handle' => '',
                ]);

                $oauthSession = retrieveSession();

                if (isset($oauthSession ['oauth_token'])) {
                    $xero->config ['access_token'] = $oauthSession ['oauth_token'];
                    $xero->config ['access_token_secret'] = $oauthSession ['oauth_token_secret'];

                    // Show validation for all invoices.
                    $url = 'https://api.xero.com/api.xro/2.0/invoices/' . $xeroInvoiceIdentifier;
                    $response = $xero->request('POST', $url, [], $xeroXmlString, 'json');
                    return $response;
                }
            } else {
                return false;
            }
        }
    }

    /**
     * Send synchronous email for xero invoicing error.
     *
     * @param String $subject the subject of the email.
     * @param String $message the body of the email.
     * @param Array $to the array of receivers.
     *
     * @return mixed fired email job.
     */
    private function sendXeroInvoicingEmail($subject, $message, $to = [])
    {
        $to = !empty($to) ? $to : [config('constants.EMAIL_ADDRESS_INVOICE_NOTIFICATIONS')];
        $job = (new EmailSenderSimple($subject, $message, $to));
        return $this->dispatch($job);
    }
}
