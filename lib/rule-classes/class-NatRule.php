<?php

/*
 * Copyright (c) 2014 Palo Alto Networks, Inc. <info@paloaltonetworks.com>
 * Author: Christophe Painchaud <cpainchaud _AT_ paloaltonetworks.com>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/

class NatRule extends Rule
{

	/** @var $service null|Service|ServiceGroup Destination port associated to this NatRule. Null means 'any' */
	public $service = null;
	
	protected $snattype = 'none';

    /** @var AddressRuleContainer|null */
	public $snathosts = null;
	public $snatinterface = null;
	
	private $_snatbidir = 'no';

    /** @var null|Address|AddressGroup */
	public $dnathost = null;
	public $dnatports = null;

	/** @var null|DOMElement */
	public $snatroot = null;

	/** @ignore */
	public $dnatroot=Array();
	/** @ignore */
	public $serviceroot = null;

	static protected $templatexml = '<entry name="**temporarynamechangeme**"><from><member>any</member></from><to><member>any</member></to>
<source><member>any</member></source><destination><member>any</member></destination><service>any</service><disabled>no</disabled></entry>';
	static protected $templatexmlroot = null;

	/**
	 * @param RuleStore $owner
	 * @param bool $fromTemplateXML
	 */
	public function __construct($owner, $fromTemplateXML=false)
	{
		$this->owner = $owner;

		$this->parentAddressStore = $this->owner->owner->addressStore;
		$this->parentServiceStore = $this->owner->owner->serviceStore;

		$this->tags = new TagRuleContainer($this);

		$this->from = new ZoneRuleContainer($this);
		$this->from->name = 'from';

		$this->to = new ZoneRuleContainer($this);
		$this->to->name = 'to';

		$this->source = new AddressRuleContainer($this);
		$this->source->name = 'source';
		$this->source->parentCentralStore = $this->parentAddressStore;

		$this->destination = new AddressRuleContainer($this);
		$this->destination->name = 'destination';
		$this->destination->parentCentralStore = $this->parentAddressStore;
		
		$this->snathosts = new AddressRuleContainer($this);
		$this->snathosts->name = 'snathosts';
		$this->snathosts->parentCentralStore = $this->parentAddressStore;

        if( $fromTemplateXML )
        {
            $xmlElement = DH::importXmlStringOrDie($owner->xmlroot->ownerDocument, self::$templatexml);
            $this->load_from_domxml($xmlElement);
        }
	}


	public function load_from_domxml($xml)
	{
		$this->xmlroot = $xml;
		
		$this->name = DH::findAttribute('name', $xml);
		if( $this->name === FALSE )
			derr("name not found\n");
		
		//print "found rule name '".$this->name."'\n";

        $this->extract_disabled_from_domxml();
        $this->extract_description_from_domxml();
        $this->load_tags();
		$this->load_from();
		$this->load_to();
		$this->load_source();
		$this->load_destination();
		
		
		//						//
		// Destination NAT properties Extraction	//
		//						//
		$this->dnatroot = DH::findFirstElement('destination-translation', $xml);
		if( $this->dnatroot !== FALSE )
		{
			//print "rule '".$this->name."' has destination-translation\n";
			if( $this->dnatroot->hasChildNodes() )
			{
				$this->subdnatTAroot = DH::findFirstElement('translated-address', $this->dnatroot);
				if( $this->subdnatTAroot !== FALSE )
				{
					$f = $this->parentAddressStore->findOrCreate($this->subdnatTAroot->textContent, $this);

					$this->dnathost = $f;
					
					$this->subdnatTProot = DH::findFirstElement('translated-port', $this->dnatroot);
					if( $this->subdnatTProot !== FALSE  )
					{
						$this->subdnatport = $this->subdnatTProot->textContent;
					}
					
				}
			}
			
		}
		// end of destination translation extraction
		
		
		
		
		//										//
		// Source NAT properties Extraction		//
		//										//
		$this->snatroot = DH::findFirstElement('source-translation', $xml);
		if( $this->snatroot !== FALSE )
		{
			//print "we have found a source NAT\n";
			// next <tag> will determine NAT type
			$firstE = DH::firstChildElement($this->snatroot);
			$this->snattype = $firstE->nodeName;
			
			// Do we support this type of NAT ?
			if( $this->snattype != "static-ip" && $this->snattype != "dynamic-ip-and-port"  && $this->snattype != "dynamic-ip" )
				derr("SNAT type '".$this->snattype."' for rule '".$this->name."' is not supported, EXIT\n");
			
			//print "Determined NAT type ".$tcur['name']."\n";
			
			
			if( $this->snattype == "static-ip" )
			{
				$isbidrx = DH::findFirstElement('bi-directional', $firstE);
				if( $isbidrx !== FALSE )
				{
					$this->_snatbidir = $isbidrx->textContent;
				}
				$transladx = DH::findFirstElement('translated-address', $firstE);
				
				$fad = $this->parentAddressStore->findOrCreate( $transladx->textContent , $this );
				
				$this->snathosts->addObject($fad);
				$this->snathosts->xmlroot = $transladx;
			}
			// Extract of dynamic-ip-and-port
			else if( $this->snattype == "dynamic-ip-and-port" )
			{
				// Is it <translated-address> type ?
				$subtype = DH::findFirstElement('translated-address', $firstE);
				
				if( $subtype !== FALSE )
				{
					if( DH::firstChildElement($subtype) === FALSE )
					{
						// this rule has no address specified
					}
					else
					{
						foreach( $subtype->childNodes as $node )
						{
							if( $node->nodeType != 1 ) continue;
							$translad = $this->parentAddressStore->findOrCreate( $node->textContent , $this );
							$this->snathosts->addObject($translad);
						}
						
						$this->snathosts->xmlroot = $subtype;
						
					}
					
				}
				else
				{
					$subtype = DH::findFirstElement('interface-address', $firstE);
					if( $subtype !== FALSE )
					{
						if( DH::firstChildElement($subtype) === FALSE )
							derr("Cannot understand dynmaic NAT for rule '".$this->name."'\n");

						foreach( $subtype->childNodes as $node )
						{
							if( $node->nodeType != 1) continue;

							if( $node->nodeName == 'interface' )
							{
								$this->snatinterface =  $node->textContent;
							}
							else if( $node->nodeName == 'ip' )
							{
								$translad = $this->parentAddressStore->findOrCreate( $node->textContent, $this );
								$this->snathosts->addObject($translad);
							}
							else
								derr("Cannot understand dynamic NAT for rule '".$this->name."'\n");
						}
					}
					else
					{
                        mwarning("Unknown dynamic SNAT type on rule '".$this->name." don't mess too much with this rule or face unpredictable results");
					}
				}
				
				
				
			}
			
		}
		//
		// End of Source NAT properties extraction	//
		

		
		
		//  								//
		//	Begin of <service> extraction				//
		//								//
		$this->serviceroot = DH::findFirstElementOrCreate('service', $xml, 'any');
		if( $this->serviceroot !== FALSE )
		{
			$lname = $this->serviceroot->textContent;
			if( strtolower($lname) != 'any' )
			{
				//print "found service named $lname in  NAT rule '".$this->name."'\n";
				$f = $this->parentServiceStore->findOrCreate($lname, $this, true);
				if( !$f )
				{
					derr("Error: service object named '$lname' not found in NAT rule '".$this->name."'\n");
				}
				
				$this->service = $f;
			}
		}
		else
		{
			derr('unexpected error');
		}
		// End of <service> extraction 	//
		
		
	}

	
	public function referencedObjectRenamed($h)
	{
		if( $this->service === $h )
			$this->rewriteService_XML();
	}
	
	public function replaceReferencedObject($old, $new )
	{
		$found = false;
		
		if( $this->service === $old )
		{
			$found = true;
			$this->service = $new;
			$this->rewriteService_XML();
			$old->removeReference($this);
		}
		if( $this->dnathost === $old )
		{
			$found = true;
			$this->setDNAT($new, $this->dnatports);
		}
		if( $this->snathosts->has($old) )
		{
			$found = true;
			$this->snathosts->addObject($new);
			$this->snathosts->remove($old);
			if( $this->dnathost !== $old )
				$old->removeReference($this);
			$this->rewriteSNAT_XML();
		}

		
		if($found)
			$new->addReference($this);
		else
		{
			mwarning("object is not part of this nat rule : {$old->toString()}");
		}
		
	}


	public function rewriteSNAT_XML()
	{
		
		if( $this->snattype == 'none' )
		{
			if( $this->snatroot !== null )
				$this->xmlroot->removeChild($this->snatroot);
			$this->snatroot = null;
			return;
		}

		$this->snatroot = DH::createOrResetElement($this->xmlroot, 'source-translation');


			
		if( $this->snattype == 'dynamic-ip-and-port' )
		{
			$subroot = DH::createOrResetElement($this->snatroot, 'dynamic-ip-and-port');
			
			if( $this->snatinterface === null )
			{
				$subsubroot = DH::createOrResetElement($subroot, 'translated-address');

				$this->snathosts->xmlroot = $subsubroot;
				$this->snathosts->rewriteXML();
			}
			else
			{
				$subsubroot = DH::createOrResetElement($subroot, 'interface-address');

				derr("not supported yet");

				/*$subsubroot['children'][] = Array('name' => 'interface', 'content' => $this->snatinterface);
				
				if( count($this->snathosts) > 0 )
				{
					$tmpA = Array();
					
					Hosts_to_xmlA($tmpA, $this->snathosts, 'ip');
					$tmpAk = array_keys($tmpA);
					
					$subsubroot['children'][] = &$tmpA[$tmpAk[0]]; 
				}*/
				
			}
		}
		else if( $this->snattype == 'static-ip' )
		{
			$subroot = DH::createOrResetElement($this->snatroot, 'static-ip');

			foreach( $this->snathosts->all() as $obj )
			{
				DH::createOrResetElement($subroot, 'translated-address', $obj->name());
			}
			
			DH::createOrResetElement($subroot, 'bi-directional', $this->_snatbidir);
			
		}
		else
			derr("NAT type not supported for rule '".$this->snattype."'\n");
			
		//print_r($this->snatroot);
	
	}


	/**
	 * @param bool $yes
	 */
	public function setBiDirectional( $yes )
	{
		if( is_string($yes) )
		{
			if( $yes == 'yes' || $yes == 'no' )
			{
				$this->_snatbidir = $yes;
			}
			else
			{
				derr("This value is not supported: '$yes'");
			}
		}
		else
		{
			if( $yes === true || $yes === false )
			{
				$this->_snatbidir = boolYesNo($yes);
			}
			else
			{
				derr("This value is not supported: '$yes'");
			}
		}
		
		if( $this->snattype != 'static-ip' )
			derr('You cannot do this on non static NATs');
		
		$this->rewriteSNAT_XML();
	}

	/**
	 * @return bool
	 */
	public function isBiDirectional()
	{
		return yesNoBool($this->_snatbidir);
	}
	
	public function changeSourceNAT($newtype, $interface=null, $bidirectional=false)
	{
		derr('not supported yet');
		
		$this->rewriteSNAT_XML();
	}
	
	/**
	* Reset DNAT to none
	*
	*/
	public function setNoDNAT()
	{
		if( $this->dnathost === null )
			return;
		
		$this->dnathost->removeReference($this);
		$this->dnathost = null;
		$this->dnatports = null;

		$this->dnatroot->parentNode->removeChild($this->dnatroot);
		
	}
	
	public function setDNAT( $host , $ports = null)
	{
		if( $host === null )
			derr(" Host cannot be NULL");

		if( $this->dnathost !== null )
		{
			if( ! $this->snathosts->has($host) )
				$this->dnathost->removeReference($this);
		}

		$this->dnatroot = DH::createOrResetElement($this->xmlroot, 'destination-translation');
		$this->subdnatTAroot = DH::createOrResetElement($this->dnatroot, 'translated-address', $host->name());

		$this->subdnatTProot =  DH::createOrResetElement($this->dnatroot, 'translated-port');


		$this->dnathost = $host;
		$this->dnathost->addReference($this);
		$this->dnatports = $ports;

		
		if( $ports === null )
		{
			DH::removeChild($this->dnatroot, $this->subdnatTProot);
		}
		else
		{
			DH::setDomNodeText($this->subdnatTProot, $ports);
		}
		
	}
	
	public function setNoSNAT()
	{
		$this->snattype = 'none';
		$this->snathosts->setAny();
		$this->rewriteSNAT_XML();
		
	}
	
	public function setService($newServiceObject)
	{
		if( $this->service )
		{
			if( $this->service === $newServiceObject )
				return;
			
			$this->service->removeReference($this);
		}
		
		$this->service = $newServiceObject;
		$this->service->addReference($this);
		
		$this->rewriteService_XML();
	}
	
	
	
	public function reLinkObjects()
	{
		// to be written
		reLinkObjs($this->src, $this);
		reLinkObjs($this->dst, $this);
		reLinkObjs($this->snathosts, $this);
		if( $this->dnathost )
			$this->dnathost->addReference($this);
		if( $this->service )
			$this->service->addReference($this);
	}

	
	/**
	*
	*
	*/
	public function rewriteService_XML()
	{
		DH::clearDomNodeChilds($this->serviceroot);

		if( $this->service === null )
		{
				DH::setDomNodeText($this->serviceroot, 'any');

			return;
		}

		DH::setDomNodeText($this->serviceroot, $this->service->name());
	}
	
	
	public function display( $padding = 0)
	{
		$padding = str_pad('', $padding);

		$dis = '';
		if( $this->disabled )
			$dis = '<disabled>';
		
		$s = '*ANY*';
		if( $this->service )
			$s = $this->service->name();
		
		print $padding."*Rule named '".$this->name."  $dis\n";
		print $padding."  From: " .$this->from->toString_inline()."  |  To:  ".$this->to->toString_inline()."\n";
		print $padding."  Source: ".$this->source->toString_inline()."\n";
		print $padding."  Destination: ".$this->destination->toString_inline()."\n";
		print $padding."  Service:  ".$s."\n";
		print $padding."    Tags:  ".$this->tags->toString_inline()."\n";
		print "\n";
	}
	
	public function natType()
	{
		return $this->snattype;
	}

	/**
	 * @return string options are : 'none' , 'dynamic', 'dynamic-ip-and-port', 'static'
	 */
	public function SourceNat_Type()
	{
		return $this->snattype;
	}

    public function isNatRule()
    {
        return true;
    }

    public function storeVariableName()
    {
        return "natRules";
    }
}



