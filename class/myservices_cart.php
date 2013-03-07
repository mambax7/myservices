<?php
/**
 * ****************************************************************************
 * myservices - MODULE FOR XOOPS
 * Copyright (c) Herv� Thouzard of Instant Zero (http://www.instant-zero.com)
 * Created on 20 oct. 07 at 14:38:20
 * Version : $Id$
 * ****************************************************************************
 */

if (!defined('XOOPS_ROOT_PATH')) {
	die("XOOPS root path not defined");
}

/**
 * Classe responsable de g�rer le panier
 *
 * @package Myservices
 * @author Herv� Thouzard - Instant Zero (http://xoops.instant-zero.com)
 * @copyright (c) Instant Zero
 * @todo Utiliser un registre
 */



class myservices_Cart
{
	const CADDY_NAME =	'myservices_cart';	// Nom du panier en session

	/**
	 * Access the only instance of this class
     *
     * @return	object
     *
     * @static
     * @staticvar   object
	 */
	function &getInstance()
	{
		static $instance;
		if (!isset($instance)) {
			$instance = new myservices_Cart();
		}
		return $instance;
	}

	/**
	 * Calcul du caddy � partir du tableau en session qui se pr�sente sous la forme :
	 * 	$datas['number'] 	= indice du produit (de 1 � N)
	 * 	$datas['id'] 		= Identifiant du produit
	 * 	$datas['qty'] 		= Dur�e en heures
	 *  $datas['empid'] 	= Identifiant de l'employ�(e)
	 *  $datas['hour']		= Heure de d�but
	 *  $datas['date']		= Date de r�servation (au format YYYY-MM-DD)
	 *
	 *  Note : Les param�tres entrants de la fonction sont utilis�s comme param�tres sortants... (sic)
	 *
	 * @param array $cartForTemplate Contenu du caddy � passer au template (en fait la liste des produits)
	 * @param boolean emptyCart Indique si le panier est vide ou pas
	 * @param float $commandAmount Montant HT de la commande
	 * @param float $vatAmount Montant de la TVA
	 * @param float $commandAmountTTC Montant TTC de la commande
	 */
	function computeCart(&$cartForTemplate, &$emptyCart, &$commandAmount, &$vatAmount, &$commandAmountTTC)
	{
		global $hMsCalendar, $hMsCategories, $hMsEmployes, $hMsEmployesproducts, $hMsProducts, $hMsVat, $hMsPrefs;
		if($this->isCartEmpty()) {	// Pas de caddie
			$emptyCart = true;
		} else {
			$emptyCart = false;
			$tblCaddie = array();
			$tblCaddie = isset($_SESSION[self::CADDY_NAME]) ? $_SESSION[self::CADDY_NAME] : array();
			$caddyCount = count($tblCaddie);
			if( $caddyCount > 0 ) {
				$currency = & myservices_currency::getInstance();

				foreach($tblCaddie as $caddyElement) {
					$datas = array();
					$produit_number = $caddyElement['number'];	// Num�ro s�quentiel
					$produit_id = $caddyElement['id'];			// Identifiant Produit
					$employes_id = $caddyElement['empid'];		// Identifiant employ�(e)
					$startingHour = $caddyElement['hour'];		// Heure de d�but
					$date = $caddyElement['date'];				// Date de r�servation
					$produit_qty = $caddyElement['qty'];		// Dur�e en heures
					// On r�cup�re le produit concern�
					$product = null;
					$product = $hMsProducts->get($produit_id);
					if(!is_object($product)) {
						trigger_error(_MYSERVICES_ERROR3, E_USER_ERROR);
						return null;
					}
					// Puis l'employ�(e)
					$employee = null;
					$employee = $hMsEmployes->get($employes_id);
					if(!is_object($employee)) {
						trigger_error(_MYSERVICES_ERROR11, E_USER_ERROR);
						return null;
					}

					$datas = $product->toArray();
					$datas['id'] = $produit_id;
					$datas['empid'] = $employes_id;
					$datas['products_reserved_date'] = myservices_utils::SQLDateToHuman($date, 's');
					$datas['products_reserved_time'] = $startingHour;
					$datas['products_reserved_duration'] = $produit_qty;
					$datas['employee'] = $employee->toArray();
					$datas['products_number'] = $produit_number;
					// Donn�es utilis�es pour la cr�ation de la commande (donc non affich�es)
					$startingTimestamp = strtotime($date.' '.$startingHour);
					$endingTimestamp = $startingTimestamp + ($produit_qty * 3600);
					$datas['starting_date'] = date("Y-m-d H:i:s", $startingTimestamp);
					$datas['ending_date'] = date("Y-m-d H:i:s", $endingTimestamp);
					// Calculs "financiers" ***********************************
					$ht = floatval($product->getVar('products_price'));
					$prixReelHT = $ht * $produit_qty;
					$VATrate = $product->getVATRate();
					$montantTVA = $product->getVATAmount($caddyElement['qty'], $VATrate);

					$datas['products_amount_ht'] = $currency->amountForDisplay($prixReelHT);		// Montant HT * Quantit�
					$datas['products_vat_amount'] = $currency->amountForDisplay($montantTVA);		// Montant de la TVA
					$datas['products_vat_rate'] = $currency->amountInCurrency($VATrate);			// Taux de TVA
					$datas['products_price_ttc'] = $currency->amountForDisplay($prixReelHT + $montantTVA, 's');
					$datas['products_price_ttc_db'] = $prixReelHT + $montantTVA;
					$cartForTemplate[] = $datas;

					// Les cumuls (dans les variables "globales")
					$commandAmount += $prixReelHT;						// Montant cumul� HT
					$vatAmount += $montantTVA;							// Montant cumul� de la TVA
					$commandAmountTTC += $prixReelHT + $montantTVA;		// Montant cumul� TTC
				}
				// fin des calculs
			}
		}
	}


	/**
	 * Mise � jour des quantit�s du caddy suite � la validation du formulaire du caddy
	 */
	function updateQuantites()
	{
		global $hMsCalendar, $hMsCategories, $hMsEmployes, $hMsEmployesproducts, $hMsProducts, $hMsVat, $hMsPrefs;
		$tbl_caddie = $tbl_caddie2 = array();
		if(isset($_SESSION[self::CADDY_NAME])) {
			$tbl_caddie = $_SESSION[self::CADDY_NAME];
			foreach($tbl_caddie as $produit) {
				$number = $produit['number'];
				$name = 'qty_'.$number;
				if(isset($_POST[$name])) {
					$valeur = intval($_POST[$name]);
					if($valeur > 0) {
						$product_id = $produit['id'];
						$product = null;
						$product = $hMsProducts->get($product_id);
						if(is_object($product)) {
							$produit['qty'] = $valeur;
							$tbl_caddie2[] = $produit;
						}
					}
				}
			}
			if(count($tbl_caddie2) > 0 ) {
				$_SESSION[self::CADDY_NAME] = $tbl_caddie2;
			} else {
				unset($_SESSION[self::CADDY_NAME]);
			}
		}
	}

	/**
	 * Suppression d'un produit du caddy
	 *
	 * @param integer $indice Indice de l'�l�ment � supprimer
	 */
	function deleteProduct($indice)
	{
		$tbl_caddie = array();
		if(isset($_SESSION[self::CADDY_NAME])) {
			$tbl_caddie = $_SESSION[self::CADDY_NAME];
			if(isset($tbl_caddie[$indice])) {
				unset($tbl_caddie[$indice]);
				if(count($tbl_caddie) > 0) {
					$_SESSION[self::CADDY_NAME] = $tbl_caddie;
				} else {
					unset($_SESSION[self::CADDY_NAME]);
				}
			}
		}
	}

	/**
	 * Ajout d'un produit au caddy
	 * Note, les produits ajout�s mais d�j� pr�sents dans le panier ne sont pas dupliqu�s, on modifie la quantit�
	 *
	 * @param integer $product_id Identifiant du produit
	 * @param integer $quantity Quantit� en heures
	 * @param integer $employees_id Identifiant de l'employ�(e)
	 * @param string $hour Heure de d�but pour la prestation
	 * @param string $date Date de la prestation (au format YYYY-MM-DD)
	 */
	function addProduct($product_id, $quantity, $employees_id, $hour, $date)
	{
		$tbl_caddie = $tbl_caddie2 = array();
		if(isset($_SESSION[self::CADDY_NAME])) {
			$tbl_caddie = $_SESSION[self::CADDY_NAME];
		}
		$exists = -1;
		foreach($tbl_caddie as $key => $produit) {
			if($produit['id'] == $product_id && $produit['date'] == $date && $produit['hour'] == $hour) {
				$exists = $key;
			}
		}

		$datas = array();
		if($exists == - 1) {
			$datas['number'] = count($tbl_caddie)+1;	// Rang dans le tableau
		} else {
			$datas['number'] = $exists;	// Rang dans le tableau
		}
		$datas['id'] = $product_id;
		$datas['qty'] = $quantity;
		$datas['empid'] = $employees_id;
		$datas['hour'] = $hour;
		$datas['date'] = $date;
		$tbl_caddie[$datas['number']] = $datas;
		$_SESSION[self::CADDY_NAME] = $tbl_caddie;
	}


	/**
	 * Indique si le caddy est vide ou pas
	 *
	 * @return boolean vide, ou pas...
	 */
	function isCartEmpty()
	{
		if(isset($_SESSION[self::CADDY_NAME])) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Vidage du caddy, s'il existe
	 */
	function emptyCart()
	{
		if(isset($_SESSION[self::CADDY_NAME])) {
			unset($_SESSION[self::CADDY_NAME]);
		}
	}
}
?>