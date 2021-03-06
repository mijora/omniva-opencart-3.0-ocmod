<?php

/**
 * Generating omnivalt shipping options
 * parcel_terminal option needs to have terminals saved in session
 * vqmod edits shipping_method.php to manage checkout correct terminal choice.
 * courier and parcel terminals
 */
class ModelExtensionShippingOmnivalt extends Model
{
  public function getQuote($address)
  {
    $currency_carrier = "EUR";
    $cart_subtotal = $this->cart->getSubTotal();
    // weight is no longer used
    // $total_kg = $this->cart->getWeight();
    // $weight_class_id = $this->config->get('config_weight_class_id');
    // $unit = $this->db->query("SELECT unit FROM `" . DB_PREFIX . "weight_class_description` wcd WHERE (weight_class_id = " . $weight_class_id . ") AND language_id = '" . (int) $this->config->get('config_language_id') . "'");
    // $unit = $unit->row['unit'];
    // if ($unit == 'g') {
    //   $total_kg /= 1000;
    // }

    $this->load->language('extension/shipping/omnivalt');

    $method_data = array();
    $service_Actives = $this->config->get('shipping_omnivalt_service');

    if (is_array($service_Actives) && count($service_Actives) && ($address['iso_code_2'] == 'LT' ||
      $address['iso_code_2'] == 'LV' ||
      $address['iso_code_2'] == 'EE')) {
      foreach ($service_Actives as $service_Active) {
        $price_target = 'shipping_omnivalt_' . $service_Active . '_price';
        if ($address['iso_code_2'] != 'LT' && ($address['iso_code_2'] == 'LV' || $address['iso_code_2'] == 'EE')) {
          $price_target .= strtolower($address['iso_code_2']);
        }
        $price = $this->config->get($price_target);

        // in price settings ranges can be done using format - cart_subtotal1:price1 ; cart_subtotal2:price2
        if (stripos($price, ':') !== false) {
          $prices = explode(';', $price);
          if (!is_array($prices)) {
            continue;
          }
          $price = false;
          foreach ($prices as $range) {
            $priceArr = explode(':', str_ireplace(array(' ', ';'), '', $range));
            if (!is_array($priceArr) || count($priceArr) != 2) {
              continue;
            }
            if ($priceArr[0] <= $cart_subtotal) {
              $price = $priceArr[1];
            }
          }
        }
        if ($price === false) {
          continue;
        }

        $title = $this->language->get('text_' . $service_Active);
        $codeCarrier = "omnivalt";
        $tax_class_id = $this->config->get('shipping_omnivalt_tax_class_id');
        switch ($service_Active) {
          case 'parcel_terminal':
            $cabins = $this->loadTerminals();
            $terminals = $this->groupTerminals($cabins, $address['iso_code_2']);
            $cost = $this->currency->convert($price, $currency_carrier, $this->config->get('config_currency'));
            $sort_order = $this->config->get('shipping_omnivalt_sort_order');
            $text = $this->currency->format(
              $this->tax->calculate(
                $this->currency->convert($price, $currency_carrier, $this->session->data['currency']),
                $tax_class_id,
                $this->config->get('config_tax')
              ),
              $this->session->data['currency']
            );

            foreach ($terminals as $code => $terminal) {
              $quote_data[$service_Active . "_$code"] = array(
                'code' => $codeCarrier . '.' . $service_Active . "_$code",
                'title' => $terminal,
                'head' => $title,
                'cost' => $cost,
                'tax_class_id' => $tax_class_id,
                'sort_order' => $sort_order,
                'text' => $text,
              );
            }

            // add fake quote
            $quote_data[$service_Active] = array(
              'code' => 'fake.' . $service_Active,
              'title' => $title,
              'head' => $title,
              'cost' => $cost,
              'tax_class_id' => $tax_class_id,
              'sort_order' => $sort_order,
              'text' => $text,
            );
            break;

          case 'courier':
            $quote_data[$service_Active] = array(
              'code' => $codeCarrier . '.' . $service_Active,
              'title' => $title,
              'head' => $title,
              'cost' => $this->currency->convert($price, $currency_carrier, $this->config->get('config_currency')),
              'tax_class_id' => $tax_class_id,
              'sort_order' => $this->config->get('shipping_omnivalt_sort_order') + 1,
              'text' => $this->currency->format(
                $this->tax->calculate(
                  $this->currency->convert($price, $currency_carrier, $this->session->data['currency']),
                  $tax_class_id,
                  $this->config->get('config_tax')
                ),
                $this->session->data['currency']
              ),
            );
            break;
        }
      }

      if (!(isset($quote_data)) || !is_array($quote_data)) {
        return '';
      }

      $sort_order = array();

      foreach ($quote_data as $key => $value) {
        $sort_order[$key] = $value['sort_order'];
      }

      array_multisort($sort_order, SORT_ASC, $quote_data);

      $method_data = array(
        'code' => 'omnivalt',
        'title' => $this->language->get('text_title'),
        'quote' => $quote_data,
        'sort_order' => $this->config->get('shipping_omnivalt_sort_order'),
        'error' => false,
      );
    }
    $this->session->data['omniva_country_loaded'] = $address['country_id'];
    return $method_data;
  }

  private function loadTerminals()
  {
    $terminals_json_file_dir = DIR_DOWNLOAD . "omniva_terminals.json";
    if (!file_exists($terminals_json_file_dir))
      return false;
    $terminals_file = fopen($terminals_json_file_dir, "r");
    if (!$terminals_file)
      return false;
    $terminals = fread($terminals_file, filesize($terminals_json_file_dir) + 10);
    fclose($terminals_file);
    $terminals = json_decode($terminals, true);
    return $terminals;
  }

  private function groupTerminals($terminals, $country = 'LT', $selected = '')
  {
    // sort terminals by coordinates
    usort($terminals, function ($a, $b) {
      if ($a[1] == $b[1]) {
        return ($a[0] < $b[0]) ? -1 : 1;
      }
      return ($a[1] < $b[1]) ? -1 : 1;
    });

    $parcel_terminals = [];
    if (is_array($terminals)) {
      $count = 0;
      foreach ($terminals as $terminal) {
        if (isset($terminal[5]) && $terminal[5] == $country && $count < 500) {
          $parcel_terminals[(string) $terminal[3]] = $terminal[0] . ', ' . $terminal[2];
          ++$count;
        }
      }
    }
    return $parcel_terminals;
  }

  public function getTerminalForMap($country = "LT")
  {
    $terminals_json_file_dir = DIR_DOWNLOAD . "locations.json";
    $terminals_file = fopen($terminals_json_file_dir, "r");
    $terminals = fread($terminals_file, filesize($terminals_json_file_dir) + 10);
    fclose($terminals_file);
    $terminals = json_decode($terminals, true);

    if (is_array($terminals)) {
      $grouped_options = array();
      $terminalsList = array();
      foreach ($terminals as $terminal) {
        if ($terminal['A0_NAME'] != $country && in_array($country, array("LT", "EE", "LV")) || intval($terminal['TYPE']) == 1)
          continue;

        if (!isset($grouped_options[$terminal['A1_NAME']]))
          $grouped_options[(string) $terminal['A1_NAME']] = array();
        $grouped_options[(string) $terminal['A1_NAME']][(string) $terminal['ZIP']] = $terminal['NAME'];

        $terminalsList[] = [$terminal['NAME'], $terminal['Y_COORDINATE'], $terminal['X_COORDINATE'], $terminal['ZIP'], $terminal['A1_NAME'], $terminal['A2_NAME'], $terminal['comment_lit']];
      }
    }
    return $terminalsList;
  }
}
