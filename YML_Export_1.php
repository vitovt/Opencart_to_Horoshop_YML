<?php
/**
 * YML generator for Horoshop.ua
 */
 
require_once __DIR__ . '/config.php';
    $x_pretty = 1; //Человекочитабельный формат или в одну строку

if(!isset($_GET[XML_KEY])) {
    date_default_timezone_set('Europe/Kiev');
    $yGenerator = new YGenerator();

    //ОПЦИИ
    $yGenerator->x_lang = 2; //Язык по умолчанчию (0, чтобы проигнорировать)
    //Выводить снятые с публикации
    //Выводить без картинок
    //Выводить с нулевым наличием
    //Выводить без названия

    /*Что делать если:
    - только один язык
    */


    $xml = $yGenerator->getYml();
    Header('Content-type: text/xml');

    if($x_pretty) {
      $doc = new DOMDocument();
      $doc->preserveWhiteSpace = false;
      $doc->formatOutput = true;
      $doc->loadXML($xml->asXML());
      echo $doc->saveXML();
    } else {
      print($xml->asXML());
    }

}else echo '-= fuck off =-';

// http://coffeerings.posterous.com/php-simplexml-and-cdata
class SimpleXMLExtended extends SimpleXMLElement {
  public function addCData($cdata_text) {
    $node = dom_import_simplexml($this); 
    $no   = $node->ownerDocument; 
    $node->appendChild($no->createCDATASection($cdata_text)); 
  } 
}

/**
 * Making YML layout depending Rozetka's pattern (rozetka.com.ua/sellerinfo/pricelist/)
 * Class YGenerator
 */
class YGenerator
{

    public $x_lang; //Язык по умолчанчию (0, чтобы проигнорировать)

    private $languages;

    /**
     * Building YML
     * @return SimpleXMLElement
     */
    function getYml()
    {
        require_once __DIR__ . '/config.php';
        $con = mysqli_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

        if (!$con) {
            echo "Error: Unable to connect to MySQL." . PHP_EOL;
            echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
            echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
            die();
        }

        mysqli_set_charset($con, "utf8");

        $this->languages = $this->getLanguages($con);
	$base_url = $_SERVER['SERVER_NAME'];
        $base_url = sprintf(
          "%s://%s",
          isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 'https' : 'http',
          $_SERVER['SERVER_NAME']
        );

        $xml = new SimpleXMLExtended("<?xml version=\"1.0\" encoding=\"UTF-8\"?><yml_catalog/>");
        $dt = date("Y-m-d");
        $tm = date("H:i");
        $xml->addAttribute("date", $dt . ' ' . $tm);


        $shop = $xml->addChild('shop');
        $shop->addChild('name', "Horoshop-Export");
        $shop->addChild('company', "Horoshop");
        $shop->addChild('url', "https://www.horoshop.ua/");
        $shop->addChild('version', "1.0.1");

        $currencies = $shop->addChild('currencies');
        $currency = $currencies->addChild('currency');
        $currency->addAttribute("id", "UAH");
        $currency->addAttribute("rate", "1");

        //show service info
        $languages = $shop->addChild('languages');
        foreach($this->languages as $key=>$value) {
          $language = $languages->addChild('language', $value['name']);
          $language->addAttribute("id", $key);
          $language->addAttribute("name", $value['name']);
          $language->addAttribute("code", $value['code']);
        }

        // #### Categories Section ####
        $categories = $shop->addChild('categories');
        $sql = "SELECT `category_id`, `name` FROM `oc_category_description` WHERE 1";
        if($this->x_lang) { $sql .= " AND language_id = $this->x_lang"; }
        $sql .= ' ORDER BY `category_id`'; 
        $result = $con->query($sql);
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $category = $categories->addChild('category', htmlspecialchars($row['name'])); //echo $row['name'] . PHP_EOL;
                $category->addAttribute("id", $row['category_id']);
                $parentId = $this->getParentIdCategory($con, $row['category_id']);
                if ($parentId != 0) {
                    $category->addAttribute("parentId", $parentId);
                }
            }
        }
        //#### End Categories Section ####

        // #### Offers Section ####
        $offers = $shop->addChild('offers');
        //$sql = "SELECT * FROM  `oc_product` WHERE `quantity` > 0";
        $sql = "SELECT * FROM  `oc_product`";
        $result = $con->query($sql);
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $productId = $row['product_id'];
                $manufacturerId = $row['manufacturer_id'];
                $stock_quantity = $row['quantity'];

                // #### Attribute section ####
                $listAttributes = array();
                $sql3 = "SELECT * FROM `oc_product_attribute` WHERE `product_id` = '$productId'";
                if($this->x_lang) { $sql3 .= " AND language_id = $this->x_lang"; }
                $result3 = $con->query($sql3);
//                if ($result3->num_rows > 0) { //Не выгружать без атрибутов
                    while ($row3 = $result3->fetch_assoc()) {
                        $data = array();
                        $nameAttribute = $this->getNameAttributeById($con, $row3['attribute_id']);
                        $data['nameAttribute'] = $nameAttribute;
                        $valueAttribute = htmlspecialchars($row3['text']);
                        $data['valueAttribute'] = $valueAttribute;
                        $data['sortOrder'] = $this->getAttributeSortOrder($con, $row3['attribute_id']);

                        array_push($listAttributes, $data);
                    }
                        $alloptions = $this->getAllProductOptions($con, $productId);

                    //checking, how many attributes in product && if exist image of products
                    //don't adding the product if min attributes
//////                    if (count($listAttributes) > 4 && strlen($row['image']) > 4) {
                        $offer = $offers->addChild('offer');
                        $offer->addAttribute("id", $row['product_id']);
                        $offer->addAttribute("available", "true");
                        $textUrl = $base_url . '/index.php?route=product/product&amp;product_id=' . $row['product_id'];

                        //OPTIONS
                        //if($alloptions) { $offer->addChild('options', var_export($alloptions, true)); }
                        if($alloptions) {
                          $options = $offer->addChild('options');
                          foreach($alloptions as $option_values) {
                            //$option = $options->addChild('option', $option_values['name']);
                            $option = $options->addChild('option');
                            $option->addAttribute('name', $option_values['option_name']);
                            /*$option->addChild('name', $option_values['name']);
                            $option->addChild('price', $option_values['price']);
                            $option->addChild('artikul', $option_values['artikul']);
                            $option->addChild('barcode', $option_values['barcode']);
                            $option->addChild('image', $option_values['image']);*/
                            foreach($option_values as $key=>$value) {
                               if($value) {
                                // echo $key . ':' . $value . PHP_EOL;
                                 $option->addChild(str_replace('1c', 'one_c', $key), htmlspecialchars($value));
                               }
                            }
                            //price, artikul, barcode, image
                          }
                        }
                        $offer->addChild('url', $textUrl);
                        $offer->addChild('price', $row['price']);
                        $offer->addChild('currencyId', 'UAH');
                        $offer->addChild('categoryId', $this->getCategoryOfProduct($con, $row['product_id']));
			if($row['image']) {
                          $img = $base_url . '/image/' . $row['image'];
                          $offer->addChild('picture', $img);
                        }

                        //Multiple pictures section
                        $sql3 = "SELECT * FROM `oc_product_image` WHERE `product_id` = '$productId' ORDER BY sort_order ASC";
                        $result3 = $con->query($sql3);
                        if ($result3->num_rows > 0) {
                          while ($row3 = $result3->fetch_assoc()) {
                            $img = $base_url . '/image/' . $row3['image'];
                            $offer->addChild('picture', $img);
                          }
                        }
 
                        $vendorName = $this->getVendorName($con, $manufacturerId);
                        $offer->addChild('vendor', $vendorName);
                        $offer->addChild('stock_quantity', $stock_quantity);
                        //$offer->addChild('store', "false");
                        //$offer->addChild('pickup', "false");
                        //$offer->addChild('delivery', "false");

                        $sql2 = "SELECT * FROM `oc_product_description` WHERE `product_id` = '$productId'";
                        if($this->x_lang) { $sql2 .= " AND language_id = $this->x_lang"; }
                        $result2 = $con->query($sql2);
                        if ($result2->num_rows > 0) {
                            while ($row2 = $result2->fetch_assoc()) {
                                //$text = $this->removeTags($row2['description']);
                                $text = $row2['description'];
                                $name = $offer->addChild('name', htmlspecialchars($row2['name']));
                                if (strlen(trim($text)) == 0) {
                                    $offer->addChild('description'); //Empty description if doesnt' exists
                                } else {
                                    //$offer->addChild('description');
                                    $offer->description = NULL;
                                    $offer->description->addCData($text);
                                    //$offer->addChild('description', $text);
                                }
                            }
                        }

//                    sort array by `sortOrder`
                        for ($i = 0; $i < count($listAttributes); $i++) {
                            for ($j = $i + 1; $j < count($listAttributes); $j++) {
                                if ($listAttributes[$i]['sortOrder'] > $listAttributes[$j]['sortOrder']) {
                                    $temp = $listAttributes[$j];
                                    $listAttributes[$j] = $listAttributes[$i];
                                    $listAttributes[$i] = $temp;
                                }
                            }
                        }

                        ### Adding attributes
                        for ($i = 0; $i < count($listAttributes); $i++) {
                            $valueAttribute = trim($listAttributes[$i]['valueAttribute']);
                            $valueAttribute = $this->cutExtraCharacters($valueAttribute);
                            $param = $offer->addChild('param', $valueAttribute);
                            $param->addAttribute('name', $listAttributes[$i]['nameAttribute']);
                        }
                   /////// }
                //}
            }
        }
        return $xml;
    }

    /**
     * @param $str
     * @return mixed
     */
    private function cutExtraCharacters($str){
        $cyr = [
            ' кг', ' л', ' Вт', ' куб. м/ч', ' см', ' дБ'
        ];

        //$str = str_replace($cyr, '', $str);
        return $str;
    }


    /**
     * Getting attribute sort order by id
     * @param $con
     * @param $attributeId
     * @return int
     */
    private function getAttributeSortOrder($con, $attributeId)
    {
        $sql = "SELECT `sort_order` FROM `oc_attribute` WHERE `attribute_id` = '$attributeId'";
        $result = $con->query($sql);
        $sortOrder = 0;
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $sortOrder = $row['sort_order'];
            }
        }
        if ($sortOrder == 0) $sortOrder = 50;
        return $sortOrder;
    }

    /**
     * Getting name of the attribute by id
     * @param $con
     * @param $attributeId
     * @return string
     */
    private function getNameAttributeById($con, $attributeId)
    {
        $sql = "SELECT `name` FROM `oc_attribute_description` WHERE `attribute_id` = '$attributeId'";
        if($this->x_lang) { $sql .= " AND language_id = $this->x_lang"; }
        $result = $con->query($sql);
        $name = '';
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $name = $row['name'];
            }
        }
        return $name;
    }

    /**
     * Removing HTML tags and other garbage
     * @param $str
     * @return string
     */
    private function removeTags($str)
    {
        $str = preg_replace('/<meta([^&]*)\/>/', '', $str);
        $str = preg_replace('/&(?!#?[a-z0-9]+;)/', '&amp;', $str);
        $str = preg_replace('/<[^>]*>/', '', $str);
        $str = str_replace('P.S. В случае отсутствия товара, оставьте заявку на нашем сайте!', '', $str);
        $str = str_replace('Характеристики и комплектация товара могут изменяться производителем без уведомления', '', $str);
//    $str = $str.replaceAll("<[^>]*>", "");
        return $str;
    }

    /**
     * Getting ID of category by ID of the product
     * @param $con
     * @param $productId
     * @return int
     */
    private function getCategoryOfProduct($con, $productId)
    {
        $sql = "SELECT `category_id` FROM `oc_product_to_category` WHERE `product_id` = '$productId'";
        $result = $con->query($sql);
        $categoryId = 0;
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $categoryId = $row['category_id'];
            }
        }
        return $categoryId;
    }

    /**
     * Getting ID of parent category by ID of the current category
     * @param $con
     * @param $codeGroup
     * @return int
     */
    private function getParentIdCategory($con, $codeGroup)
    {
        $sql = "SELECT `parent_id` FROM `oc_category` WHERE `category_id` = '$codeGroup'";
        $result = $con->query($sql);
        $parentId = 0;
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $parentId = $row['parent_id'];
            }
        }
        return $parentId;
    }

    /**
     * Getting name of Vendor of the product by manufacturer Id
     * @param $con
     * @param $manufacturerId
     * @return string
     */
    private function getVendorName($con, $manufacturerId)
    {
        $sql = "SELECT `name` FROM  `oc_manufacturer` WHERE `manufacturer_id` = '$manufacturerId'";
        $result = $con->query($sql);
        $nameVendor = '';
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $nameVendor = $row['name'];
            }
        }
        return htmlspecialchars($nameVendor);
    }

    /**
     * Getting list of avaliable languages
     * @return array
     */
    private function getLanguages($con)
    {
        $sql = "SELECT * FROM oc_language WHERE `status` = '1'";
        $result = $con->query($sql);
        $languages = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
		$languages[$row['language_id']]['name'] = $row['name'];
		$languages[$row['language_id']]['code'] = $row['code'];
            }
        }
        return $languages;
    }

        public function getProductOptions($option_ids, $product_id) {
                $lang = (int)$this->x_lang;

                $sql = ("SELECT pov.*, od.name AS option_name, ovd.name, ov.image
                        FROM " . DB_PREFIX . "product_option_value pov
                        LEFT JOIN " . DB_PREFIX . "option_value ov ON (ov.option_value_id = pov.option_value_id)
                        LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON (pov.option_value_id = ovd.option_value_id)
                        LEFT JOIN " . DB_PREFIX . "option_description od ON (od.option_id = pov.option_id) AND (od.language_id = '$lang')
                        WHERE pov.option_id IN (". implode(',', array_map('intval', $option_ids)) .") AND pov.product_id = '". (int)$product_id."'
                                AND ovd.language_id = '$lang'");
                $result = $con->query($sql);
                return $result->fetch_all(MYSQL_ASSOC);
        }

        public function getAllProductOptions($con, $product_id) {
                $lang = (int)$this->x_lang;

                $sql = ("SELECT pov.*, od.name AS option_name, ovd.name, ov.image
                        FROM " . DB_PREFIX . "product_option_value pov
                        LEFT JOIN " . DB_PREFIX . "option_value ov ON (ov.option_value_id = pov.option_value_id)
                        LEFT JOIN " . DB_PREFIX . "option_value_description ovd ON (pov.option_value_id = ovd.option_value_id)
                        LEFT JOIN " . DB_PREFIX . "option_description od ON (od.option_id = pov.option_id) AND (od.language_id = '$lang')
                        WHERE pov.product_id = '". (int)$product_id."'
                                AND ovd.language_id = '$lang'");
                $result = $con->query($sql);
                return $result->fetch_all(MYSQL_ASSOC);
        }
}
