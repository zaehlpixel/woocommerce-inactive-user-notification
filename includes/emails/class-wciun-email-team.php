<?php
/**
 * Team-Übersicht und Alarme.
 *
 * @package WCIUN
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WCIUN_Email_Team', false ) && class_exists( 'WC_Email' ) ) :

	/**
	 * Sammelmail an das Shop-Team: Anzahlen und Link auf die Admin-Ansicht, keine Kundendaten.
	 */
	class WCIUN_Email_Team extends WC_Email {

		/** @var array */
		protected $payload = array();

		public function __construct() {
			$this->id             = 'wciun_team_digest';
			$this->customer_email = false;
			$this->title          = __( 'Inaktive Konten: Übersicht für das Team', 'wciun' );
			$this->description    = __( 'Tägliche Sammelmail mit Anzahlen (nur an Tagen mit Neuem oder bei Problemen) und sofortige Alarme. Empfänger werden unter Einstellungen → Inaktive Konten festgelegt.', 'wciun' );
			$this->template_base  = WCIUN_DIR . 'templates/';
			$this->template_html  = 'emails/team-digest.php';
			$this->template_plain = 'emails/plain/team-digest.php';
			parent::__construct();
		}

		public function get_default_subject() {
			return __( '[{site_title}] Inaktive Kundenkonten: Übersicht', 'wciun' );
		}

		public function get_default_heading() {
			return __( 'Inaktive Kundenkonten', 'wciun' );
		}

		public function init_form_fields() {
			parent::init_form_fields();
			// Empfänger werden zentral in den Plugin-Einstellungen gepflegt.
			unset( $this->form_fields['recipient'] );
		}

		public function get_subject() {
			$subject = parent::get_subject();
			if ( ! empty( $this->payload['is_alert'] ) ) {
				$subject = '[ACHTUNG] ' . $subject;
			}
			return $subject;
		}

		/**
		 * @param array $payload Inhalt.
		 * @return bool Vom Mailserver angenommen?
		 */
		public function trigger( $payload = array() ) {
			$this->setup_locale();
			$this->payload   = (array) $payload;
			$this->recipient = WCIUN_Settings::team_recipients();
			$sent            = false;
			if ( $this->is_enabled() && $this->get_recipient() ) {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			}
			$this->restore_locale();
			return $sent;
		}

		protected function template_args() {
			return array(
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'payload'            => $this->payload,
				'admin_url'          => admin_url( 'admin.php?page=wciun-accounts' ),
				'sent_to_admin'      => true,
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

return new WCIUN_Email_Team();
