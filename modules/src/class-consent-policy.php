<?php
/**
 * Loaded by modules/consent-policy.php only when the old MU copy is absent.
 */

defined( 'ABSPATH' ) || exit;


final class GT_Core_Forms_MailerPress_Policy {
    const OPTION = 'gt_core_forms_mailerpress_policy';
    private static $native;

    public static function boot(): void {
        if (untrailingslashit(home_url()) !== 'https://gauravtiwari.org') return;
        $hook = 'cf_process_form_action_mailerpress';
        foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_array($fn) && $fn[0] instanceof \Core_Forms\Actions\MailerPress && $fn[1] === 'process') {
                    self::$native = $fn[0];
                    remove_action($hook, $fn, $priority);
                    add_action($hook, [self::class, 'process'], $priority, 3);
                }
            }
        }
        add_action('cf_output_form_action_mailerpress_settings', [self::class, 'explain'], 30, 2);
    }

    public static function contract(array $settings): array {
        $out = [];
        foreach (['type','_cf_action_id','email_field','first_name_field','last_name_field','list_id','tag_id','double_optin'] as $key) {
            $value = $settings[$key] ?? '';
            $out[$key] = is_scalar($value) ? trim((string)$value, '[] ') : '!invalid';
        }
        $out['conditions'] = $settings['conditions'] ?? [];
        return $out;
    }

    private static function skip(string $message) {
        return \Core_Forms\Integrations\IntegrationResult::state('skipped', $message);
    }

    private static function fail(string $message) {
        return \Core_Forms\Integrations\IntegrationResult::failure($message);
    }

    public static function process(array $settings, $submission, $form) {
        return \Core_Forms\Workflows\ActionRunner::native($settings, $submission, $form, static function () use ($settings, $submission, $form) {
            global $wpdb;
            $policy = get_option(self::OPTION, []);
            $rule = $policy['forms'][(string)$form->ID] ?? null;
            if (!$rule || !self::$native) return self::fail('MailerPress capture requires a reviewed site consent route.');
            if (\Core_Forms\Workflows\ActionRunner::is_testing()) return self::skip('Live MailerPress action tests are disabled by the site no-send policy.');
            if (!empty($submission->is_spam)) return self::skip('Spam submissions are not added to MailerPress.');
            if (empty($submission->submitted_at) || strcmp($submission->submitted_at, $policy['installed_at_utc']) < 0) {
                return self::skip('Historical submissions are not enrolled by this integration.');
            }
            $identity = (string)($settings['_cf_action_id'] ?? '');
            $route = $rule['routes'][$identity] ?? null;
            $current = null;
            foreach ((array)($form->settings['actions'] ?? []) as $action) {
                if (is_array($action) && ($action['_cf_action_id'] ?? '') === $identity) $current = $action;
            }
            if (!$route || !$current || self::contract($settings) !== $route['contract'] || self::contract($current) !== $route['contract']) {
                return self::fail('The MailerPress route changed and needs consent/mapping review.');
            }
            $definition = $form->get_schema() ?: \Core_Forms\Schema\MarkupParser::parse((string)$form->markup);
            $gate = null;
            foreach ($definition->get_input_fields() as $field) if ($field->name === $rule['consent_field']) $gate = $field;
            if (!$gate || !in_array($gate->type, ['checkbox','consent'], true) || $gate->checked || $gate->options ||
                $gate->label !== $rule['disclosure'] || (bool)$gate->required !== $rule['required'] ||
                ($gate->default_value !== '' ? $gate->default_value : '1') !== $rule['consent_value']) {
                return self::fail('The MailerPress consent control is missing or changed.');
            }
            $value = $submission->data[$rule['consent_field']] ?? null;
            if (!is_string($value) || $value !== $rule['consent_value']) return self::skip('Newsletter consent was not given; no MailerPress contact was created.');
            if (!cf_evaluate_action_conditions($settings['conditions'] ?? [], $submission->data)) return self::skip('The selected mailing preference does not match this route.');
            if (!empty($route['preference'])) {
                $choices = $submission->data['subscriptions'] ?? [];
                if (!is_array($choices) || !in_array($route['preference'], $choices, true)) return self::skip('This mailing preference was not selected.');
            }
            $email = $submission->data[$rule['email_field']] ?? null;
            if (!is_string($email) || !is_email($email)) return self::fail('The configured MailerPress email field is missing or invalid.');
            if (!empty($settings['double_optin'])) return self::fail('Confirmation sending is disabled for these forms.');
            $confirmation = mailerpress_get_signup_confirmation_option();
            if (!empty($policy['no_send'])) {
                if (!is_array($confirmation) || !empty($confirmation['enableSignupConfirmation'])) return self::fail('MailerPress confirmation settings require review before capture.');
                $active = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mailerpress_automations WHERE UPPER(status) NOT IN ('DRAFT','DISABLED','INACTIVE')");
                if ($wpdb->last_error || $active === null || (int)$active > 0) return self::fail('MailerPress automation state requires review before capture.');
            }
            $list_id = (int)$settings['list_id'];
            $list = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}mailerpress_lists WHERE list_id = %d", $list_id));
            if ($wpdb->last_error || $list !== $route['list_name']) return self::fail('The MailerPress destination list is missing or changed.');
            foreach ($route['tags'] as $id => $name) {
                $actual = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}mailerpress_tags WHERE tag_id = %d", (int)$id));
                if ($wpdb->last_error || $actual !== $name) return self::fail('A MailerPress source or preference tag is missing or changed.');
            }
            $contact = (new \MailerPress\Models\Contacts())->getContactByEmail($email);
            if ($wpdb->last_error) return self::fail('MailerPress contact status could not be checked.');
            if ($contact) {
                if (($contact->subscription_status ?? '') !== 'subscribed' || in_array(strtolower((string)($contact->engagement_status ?? '')), ['bounced','invalid','suppressed','complained','spam'], true)) {
                    return self::skip('The existing MailerPress opt-out or suppression status was preserved.');
                }
                $suppressed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}mailerpress_contact_tags ct JOIN {$wpdb->prefix}mailerpress_tags t ON t.tag_id = ct.tag_id WHERE ct.contact_id = %d AND t.name LIKE %s", (int)$contact->contact_id, 'Suppression:%'));
                if ($wpdb->last_error || $suppressed === null) return self::fail('MailerPress suppression tags could not be checked.');
                if ((int)$suppressed > 0) return self::skip('The existing MailerPress suppression tag was preserved.');
            }
            // The plugin remains the sole contact/list integration owner. This
            // filter narrows its data and supplies the site's consent evidence.
            $filter = static function ($data, $submitted, $target) use ($submission, $form, $rule, $route, $contact) {
                if ($submitted !== $submission || (int)$target->ID !== (int)$form->ID) return $data;
                $data['tags'] = array_map(static fn($id) => ['id'=>(int)$id], array_keys($route['tags']));
                $data['update_contact_fields'] = false; // Never change an existing status, including a concurrent opt-out.
                $data['optin_details'] = [
                    'source'=>'core_forms', 'form'=>$form->slug, 'consent_field'=>$rule['consent_field'],
                    'consent_value'=>$rule['consent_value'], 'consent_recorded_at'=>$submission->submitted_at,
                    'consent_version'=>'gt-core-forms-optin-2026-09-21', 'disclosure'=>$rule['disclosure'],
                ];
                $data['custom_fields'] = [
                    'consent_form'=>$form->slug, 'consent_recorded_at'=>substr($submission->submitted_at,0,10),
                    'consent_version'=>'gt-core-forms-optin-2026-09-21',
                ];
                if (!$contact) $data['custom_fields']['acquisition_source'] = 'core_forms';
                return $data;
            };
            add_filter('cf_mailerpress_action_contact_data', $filter, PHP_INT_MAX, 3);
            try { $result = self::$native->process($settings, $submission, $form); }
            finally { remove_filter('cf_mailerpress_action_contact_data', $filter, PHP_INT_MAX); }
            if (!is_array($result) || empty($result['success'])) return self::fail('MailerPress did not accept this signup.');
            $evidence = (int)$submission->id > 0 ? ['consent'=>[
                'form'=>$form->slug, 'field'=>$rule['consent_field'], 'value'=>$rule['consent_value'],
                'recorded_at_utc'=>$submission->submitted_at, 'version'=>'gt-core-forms-optin-2026-09-21',
                'disclosure'=>$rule['disclosure'], 'list'=>$route['list_name'],
            ]] : [];
            return \Core_Forms\Integrations\IntegrationResult::success('MailerPress accepted the selected mailing preference. No confirmation email was requested.', $evidence);
        });
    }

    public static function explain($settings, $index): void {
        $policy = get_option(self::OPTION, []);
        $rule = $policy['forms'][(string)absint($_GET['form_id'] ?? 0)] ?? null;
        echo '<p class="description">' . esc_html($rule
            ? 'Site policy: explicit newsletter consent is required. Existing opt-outs are preserved. Confirmation and live-test sending are disabled. Changes to this action require site route review.'
            : 'This MailerPress action needs a reviewed site consent route before it can capture contacts.') . '</p>';
    }
}
add_action('init', ['GT_Core_Forms_MailerPress_Policy','boot'], 20);
