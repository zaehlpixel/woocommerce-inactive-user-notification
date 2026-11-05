<?php
/**
 * Warn-E-Mail an Kunden.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WCIUN_Email_Customer_Warning', false ) && class_exists( 'WC_Email' ) ) :

	/**
	 * Eigene WooCommerce-E-Mail: erscheint unter WooCommerce → Einstellungen → E-Mails und nutzt Kopf, Fuß,
	 * Farben und Schrift des Shops. Rein transaktional: keine Werbung, kein Passwort, kein Login-Token.
	 */
	class WCIUN_Email_Customer_Warning extends WC_Email {

		/** @var string Platzhalter-Prefix für die Absicherung. */
		public $user_login = '';

		/** @var string Wenn gesetzt, geht die Mail an diese Adresse statt an den Kunden (Testmodus). */
		protected $override_to = '';

		/** @var string Echte Adresse des Kunden, nur für den Testbetreff. */
		protected $real_to = '';

		/** @var array Daten für die Vorlage. */
		protected $data = array();

		public function __construct() {
			$this->id             = 'wciun_customer_warning';
			$this->customer_email = true;
			$this->title          = __( 'Inaktives Konto: Warnung an Kunden', 'wciun' );
			$this->description    = __( 'Wird Kunden etwa vier Wochen vor der möglichen automatischen Löschung ihres inaktiven Kontos geschickt. Der Versand wird über Einstellungen → Inaktive Konten gesteuert.', 'wciun' );
			$this->template_base  = WCIUN_DIR . 'templates/';
			$this->template_html  = 'emails/customer-inactive-warning.php';
			$this->template_plain = 'emails/plain/customer-inactive-warning.php';
			$this->email_group    = 'accounts';

			$this->placeholders = array(
				'{customer_name}'          => '',
				'{username}'               => '',
				'{last_active_date}'       => '',
				'{earliest_deletion_date}' => '',
				'{login_url}'              => '',
			);

			parent::__construct();
		}

		public function get_default_subject() {
			return __( 'Ihr Kundenkonto bei {site_title}: bitte einmal anmelden, wenn Sie es behalten möchten', 'wciun' );
		}

		public function get_default_heading() {
			return __( 'Ihr Kundenkonto wird bald entfernt', 'wciun' );
		}

		public function get_default_additional_content() {
			return '';
		}

		public function get_subject() {
			$subject = parent::get_subject();
			if ( $this->override_to ) {
				$subject = '[TEST für ' . $this->real_to . '] ' . $subject;
			}
			return $subject;
		}

		/**
		 * Verschickt die Warnung. Die Rückgabe sagt nur, ob der Mailserver die Nachricht angenommen hat,
		 * nicht, ob sie zugestellt wurde.
		 *
		 * @param int    $user_id     Benutzer.
		 * @param int    $last_active Letzte Aktivität (Unix-Zeit).
		 * @param int    $earliest    Frühester Löschzeitpunkt (Unix-Zeit).
		 * @param string $override_to Testadresse oder leer.
		 * @return bool
		 */
		public function trigger( $user_id, $last_active = 0, $earliest = 0, $override_to = '' ) {
			$user = get_userdata( (int) $user_id );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				return false;
			}
			$this->setup_locale();

			$this->object      = $user;
			$this->user_login  = $user->user_login;
			$this->real_to     = $user->user_email;
			$this->override_to = $override_to && is_email( $override_to ) ? $override_to : '';
			$this->recipient   = $this->override_to ? $this->override_to : $user->user_email;

			$name = '';
			if ( class_exists( 'WC_Customer' ) ) {
				$name = (string) ( new WC_Customer( $user->ID ) )->get_billing_first_name();
			}
			if ( '' === $name ) {
				$name = (string) $user->first_name;
			}
			if ( '' === $name && false === strpos( (string) $user->display_name, '@' ) ) {
				$name = (string) $user->display_name;
			}
			if ( '' === $name ) {
				$name = __( 'Kundin, Kunde', 'wciun' );
			}

			$date_format = get_option( 'date_format' );
			$contact     = $this->get_reply_to_enabled() && is_email( $this->get_reply_to_address() ) ? $this->get_reply_to_address() : $this->get_from_address();

			$this->data = array(
				'customer_name'    => $name,
				'username'         => $user->user_login,
				'last_active_date' => wp_date( $date_format, (int) $last_active ),
				'earliest_date'    => wp_date( $date_format, (int) $earliest ),
				'login_url'        => wc_get_page_permalink( 'myaccount' ),
				'contact_email'    => $contact,
			);

			$this->placeholders['{customer_name}']          = $this->data['customer_name'];
			$this->placeholders['{username}']               = $this->data['username'];
			$this->placeholders['{last_active_date}']       = $this->data['last_active_date'];
			$this->placeholders['{earliest_deletion_date}'] = $this->data['earliest_date'];
			$this->placeholders['{login_url}']              = $this->data['login_url'];

			$sent = false;
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
			$this->restore_locale();
			return $sent;
		}

		/**
		 * Warnt beim Speichern der E-Mail-Einstellungen vor unbekannten Platzhaltern.
		 *
		 * @return bool
		 */
		public function process_admin_options() {
			$result = parent::process_admin_options();
			$known  = array_keys( $this->placeholders );
			foreach ( array( 'subject', 'heading', 'additional_content' ) as $field ) {
				$value = (string) $this->get_option( $field );
				if ( preg_match_all( '/\{[a-z_]+\}/i', $value, $m ) ) {
					foreach ( array_unique( $m[0] ) as $token ) {
						if ( ! in_array( $token, $known, true ) && ! in_array( $token, array( '{site_title}', '{site_address}', '{site_url}' ), true ) ) {
							WC_Admin_Settings::add_error(
								sprintf(
									/* translators: 1: Platzhalter, 2: erlaubte Platzhalter */
									__( 'Unbekannter Platzhalter %1$s. Er wird unverändert in die E-Mail geschrieben. Erlaubt sind: %2$s', 'wciun' ),
									esc_html( $token ),
									esc_html( implode( ', ', array_merge( $known, array( '{site_title}', '{site_address}' ) ) ) )
								)
							);
						}
					}
				}
			}
			return $result;
		}

		protected function template_args() {
			return array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'data'               => $this->data,
				'blogname'           => $this->get_blogname(),
				'sent_to_admin'      => false,
				'email'              => $this,
			);
		}

		public function get_content_html() {
			return wc_get_template_html( $this->template_html, $this->template_args() + array( 'plain_text' => false ), '', $this->template_base );
		}

		public function get_content_plain() {
			return wc_get_template_html( $this->template_plain, $this->template_args() + array( 'plain_text' => true ), '', $this->template_base );
		}
	}

endif;

return new WCIUN_Email_Customer_Warning();
