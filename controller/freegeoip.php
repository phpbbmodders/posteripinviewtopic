<?php
/**
 *
 * Poster IP in Viewtopic extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @copyright (c) 2016, Rich McGirr (RMcGirr83)
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\posteripinviewtopic\controller;

/**
 * @ignore
 */
use phpbb\exception\http_exception;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Poster IP in Viewtopic IP lookup controller
 */
class freegeoip
{
	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\request\request */
	protected $request;

	/** @var array */
	protected $tables;

	/** @var string */
	protected $err = '';

	/**
	 * Constructor
	 *
	 * @param \phpbb\auth\auth                   $auth
	 * @param \phpbb\db\driver\driver_interface  $db
	 * @param \phpbb\language\language           $language
	 * @param \phpbb\request\request             $request
	 * @param array                              $tables
	 *
	 * @access public
	 */
	public function __construct(\phpbb\auth\auth $auth, \phpbb\db\driver\driver_interface $db, \phpbb\language\language $language, \phpbb\request\request $request, $tables)
	{
		$this->auth = $auth;
		$this->db = $db;
		$this->language = $language;
		$this->request = $request;
		$this->tables = $tables;
	}

	/**
	 * Populate a popup to display information retrieved
	 *
	 * @param $post_id
	 * @return json response
	 * @access public
	 */
	public function freegeoip($post_id = 0)
	{
		$this->language->add_lang('common', 'phpbbmodders/posteripinviewtopic');

		if ($this->request->is_ajax())
		{
			$this->language->add_lang('mcp');

			$sql= 'SELECT poster_ip, forum_id
				FROM ' . $this->tables['posts'] . '
				WHERE post_id = ' . (int) $post_id;
			$result = $this->db->sql_query_limit($sql, 1);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			// Sanity check in case someone is just inserting post_ids at random
			if (!$row)
			{
				throw new http_exception(404, $this->language->lang('POST_NOT_EXIST'));
			}

			// Same rule as core for a post's IP: "Can view post details" in a forum the user can read
			if (!$this->auth->acl_get('m_info', (int) $row['forum_id']) || !$this->auth->acl_get('f_read', (int) $row['forum_id']))
			{
				throw new http_exception(403, $this->language->lang('NOT_AUTHORISED'));
			}

			// Nothing to look up for an empty, local or malformed address
			if ($row['poster_ip'] === '127.0.0.1' || filter_var($row['poster_ip'], FILTER_VALIDATE_IP) === false)
			{
				return new JsonResponse([
					'MESSAGE_TITLE'	=> $this->language->lang('ERROR'),
					'MESSAGE_TEXT'	=> $this->language->lang('IP_ADDRESS_INVALID'),
				]);
			}

			$response = $this->freegeoip_api($row['poster_ip']);

			if (!empty($this->err))
			{
				$data = [
					'MESSAGE_TITLE'	=> $this->language->lang('ERROR'),
					'MESSAGE_TEXT'	=> $this->language->lang('ERROR_FROM_SERVER', $this->err),
				];

				$json_response = new JsonResponse($data);

				return $json_response;
			}

			$json_decode = json_decode((string)$response, true);

			if (!is_array($json_decode))
			{
				return new JsonResponse([
					'MESSAGE_TITLE'	=> $this->language->lang('ERROR'),
					'MESSAGE_TEXT'	=> $this->language->lang('NO_RESPONSE_FROM_SERVER'),
				]);
			}

			$message = '';

			foreach ($json_decode as $key => $value)
			{
				$lang_key = 'PIPIV_' . strtoupper((string) $key);

				// Only the fields this extension has a label for, and only plain values
				if (in_array($key, ['as', 'org', 'status']) || !is_scalar($value) || is_bool($value) || (string) $value === '' || !$this->language->is_set($lang_key))
				{
					continue;
				}

				// The answer comes from a remote server, never print it as HTML
				$message .= '<pre>' . $this->language->lang($lang_key, htmlspecialchars(ucfirst((string) $value), ENT_COMPAT)) . '</pre>';
			}

			$data = [
				'MESSAGE_TITLE'	=> $this->language->lang('PAGE_TITLE'),
				'MESSAGE_TEXT'	=> $message,

				'success'	=> true,
			];

			$json_response = new JsonResponse($data);

			return $json_response;
		}

		throw new http_exception(405, $this->language->lang('EXTENSION_REQUIRES_JAVASCRIPT'));
	}

	/**
	 * Query the freegoip database
	 *
	 * @param $posterip
	 * @return string
	 * @access private
	 */
	private function freegeoip_api($poster_ip)
	{
		$url = 'http://ip-api.com/json/' . $poster_ip;

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => "GET",
			CURLOPT_HTTPHEADER => [
				"accept: application/json",
				"content-type: application/json"
			],
		]);

		$response = curl_exec($curl);
		$err = curl_error($curl);
		curl_close($curl);

		if (!empty($err))
		{
			$this->err = $err;
		}
		else
		{
			return $response;
		}
	}
}
