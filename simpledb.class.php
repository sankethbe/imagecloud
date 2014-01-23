<?php
/**
 * A PHP5 class for interfacing with the Amazon SimpleDB API
 *
 * Author: Alex Bosworth
 * License: MIT
 * 
 * @package SimpleDb
 * @category SimpleDb
 */

/**
 *
 */
require_once 'Crypt/HMAC.php';

class SimpleDbException extends Exception
{ 
    public function __construct($errors)
    {
        echo $errors->Error->Code . ': ' . $errors->Error->Message;
    }
}

/**
 * This class is the abstraction layer to SimpleDb
 *
 * Takes ($key, $secret, $url)
 * - [str] $key: Your Amazon Web Services Access Key ID
 * - [str] $secret: Your Amazon Web Services Secret Access Key
 * - [str] $url: OPTIONAL (default: http://sdb.amazonaws.com/)
 *
 * Example Usage:
 *
 * <pre>
 *   $sd = new SimpleDb("AWS_KEY", "AWS_SECRET");
 *
 *   $sd->listDomains();
 *   $sd->query("domain1");
 *
 *   $sd->createDomain("domain1"); 
 *   $sd->putAttributes('item1', array('name', array('value1', 'value2'));
 *
 *   $sd->getAttributes('item1');
 *
 *   $sd->deleteAttributes('item1', array('name', 'name2'));
 *   $sd->deleteDomain('domain1');
 * </pre> 
 */
class SimpleDb {
  /**#@+
   * @ignore 
   */
  private $accessUrl;
  private $accessKeyId;
  private $accessSecret;
 /**#@-*/

  /**
   * Constructor
   *
   * Takes ($key, $secret, $url)
   *
   * - [str] $key: Your Amazon Web Services  "Access Key ID"
   * - [str] $secret: Your Amazon Web Services  "Secret Access Key"
   * - [str] $url: OPTIONAL: defaults: http://sdb.amazonaws.com/ 
   *
   * @ignore
   */
  public function __construct($key, $secret, $url = "https://sdb.amazonaws.com/") 
  {
      $this->accessUrl    = $url;
      $this->accessKeyId  = $key;
      $this->accessSecret = $secret;
  }

  private static function implode_with_keys($array, $glue = '', $keyGlue = '')
  {
      $elements = array();

      foreach ($array as $key => $value)
      {
          $elements[] = $key . $keyGlue . $value;
      }

      return implode($glue, $elements);
  }

  /**
   * Convert a hex string to a base64 string
   *
   * @IGNORE
   */
  private static function hex2b64($in_str) 
  {
      $raw = '';

      for ($i = 0; $i < strlen($in_str); $i += 2) 
      {
          $raw .= chr(hexdec(substr($in_str, $i, 2)));
      }

      return base64_encode($raw);
  }

  /**
   * Sign a string
   *
   * @ignore
   */
  private static function sign($in_str, $in_key) 
  {
      $hasher =& new Crypt_HMAC($in_key, "sha1");

      $signature = self::hex2b64($hasher->hash($in_str));

      return($signature);
  }


  /**
   * Makes a request to the SimpleDb Service
   *
   * Takes ($params)
   *
   * - [arr] $params: custom query params to send to sdb
   */
  private function request($action, $params)
  {
      $params['Action'] = $action;
      $params['AWSAccessKeyId']   = $this->accessKeyId;
      $params['SignatureVersion'] = 1;
      $params['Timestamp']        = date('c', time());
      $params['Version']          = '2007-11-07';

      uksort($params, 'strnatcasecmp');

      $params['Signature'] = self::sign(self::implode_with_keys($params), $this->accessSecret);

      foreach ($params as &$param)
      {
          $param = rawurlencode($param);
      }

      $url = $this->accessUrl . '?' . self::implode_with_keys($params, '&', '=');

      $curl = curl_init($url);

      curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

      $xml = simplexml_load_string(curl_exec($curl));

      if ($xml->Errors) throw new SimpleDbException($xml->Errors);

      return $xml;
  }

  /**
   * Create a new domain
   *
   * [str] $name
   */
  public function createDomain($in_name)
  {
      if (empty($in_name)) throw new Exception('invalid name for a domain');

      return $this->request('CreateDomain', array('DomainName' => $in_name));
  }

  /**
   * Create a new domain
   *
   * [str] $name
   */
  public function deleteDomain($in_name)
  {
      if (empty($in_name)) throw new Exception('invalid name for a domain');

      return $this->request('DeleteDomain', array('DomainName' => $in_name));
  }

  /**
   * Get a list of domains
   *
   */
  public function listDomains($in_limit = 100, $in_token = NULL)
  {
      if ( !is_numeric($in_limit) or $in_limit > 100) throw new Exception('invalid limit');

      return $this->request('ListDomains', array('MaxNumberOfDomains' => $in_limit));
  }

  /**
   * Deletes data from an item
   *
   * [str] $in_domain - sdb domain item lives in
   * [str] $in_item - name of item
   */
  public function getAttributes($in_domain, $in_item)
  {
      if (empty($in_domain) or empty($in_item)) throw new Exception();

      return $this->request('GetAttributes',array('DomainName' => $in_domain,'ItemName' => $in_item));
  }
      
  /**
   * Deletes data from an item
   *
   * [str] $in_domain - sdb domain item lives in
   * [str] $in_item - name of item
   * [array] $in_data - name value pairs
   */
  public function deleteAttributes($in_domain, $in_item, $in_data)
  {
      if (empty($in_domain) or empty($in_item)) throw new Exception();

      $params = array('DomainName' => $in_domain, 'ItemName' => $in_item);

      $i = 0;

      foreach ($in_data as $name => $value)
      {
          $params['Attribute.' . $i . '.Name'] = $name;
          $params['Attribute.' . $i . '.Value'] = $value;

          $i++;
      }

      return $this->request('DeleteAttributes', $params);
  }

  /**
   * Put data into an item in a domain
   *
   * [str] $in_domain - the domain to which the item belongs
   * [str] $in_item - the unique id of the item
   * [str] $in_name - an array of name value pairs to 
   */
  public function putAttributes($in_domain, $in_item, $in_data)
  {
      if (empty($in_domain) or empty($in_item) or !count($in_data)) throw new Exception();

      $params = array('DomainName' => $in_domain, 'ItemName' => $in_item);

      $i = 0;

      foreach ($in_data as $name => $values)
      {
          foreach ($values as $value)
          {
              $params['Attribute.' . $i . '.Name'] = $name;
              $params['Attribute.' . $i . '.Value'] = $value;
              $params['Attribute.' . $i . '.Replace'] = 'true';

              $i++;
          }
      }

      if ($i > 100) throw new Exception();

      return $this->request('PutAttributes', $params);

  }

  /**
   * Query a domain to find item names
   *
   * [str] $in_domain - the domain to which the item belongs
   * [str] $in_query - query expression
   * [int] $in_limit - max number of items to get
   * [str] $in_token - next list token
   */
  
  public function query($in_domain, $in_query = '', $in_limit = 250, $in_token = NULL)
  {
      if (empty($in_domain) or !is_numeric($in_limit) or $in_limit > 250) throw new Exception();

      $params = array('DomainName' => $in_domain, 'MaxNumberOfItems' => $in_limit);
      
      if (!empty($in_query)) $params['QueryExpression'] = $in_query;

      return $this->request('Query', $params);
  }
}

  
