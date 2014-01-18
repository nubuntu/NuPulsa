<?php
class NuPulsa{
	public $db;
	private $cmd=array(
		"CEK"=>"",
		"CENTER"=>"",
		"DEP"=>"",
		"DEP"=>"",
		"HP"=>"",
		"HHP"=>"",
		"HRG"=>"",
		"INFO"=>"",
		"KIRIM"=>"",
		"LAP"=>"",
		"ON"=>"",
		"OFF"=>"",
		"MULTI"=>"",
		"REG"=>"",
		"SAL"=>"",
		"PIN"=>"",
		"YM"=>""
		);
	private $ym_login = 'vibipulsa::291841Nu';
	private $ym;
	private $now;
	private $tanggal;
	function __construct(){
		$this->now = date("Y-m-d");
		$this->tanggal = date("d");
		$this->db = NuDB::getObj();
		$this->ym = new ymMobile;		
		$this->ym->ymLogin($this->ym_login);
		
	}
	public function getObj(){
		static $instance;
		if(!$instance):
		$instance = new NuPulsa();
		endif;
		return $instance;
	}
	public function run(){
		$rows = $this->getInboxAll();
		foreach($rows as $row){
			$msg = explode(".",strtoupper($row->MSG));
			$cmd = $msg[0];
			$cnt = count($msg);
			$pin = "";
			$n=1;
			if(!isset($this->cmd[$cmd])){
				switch($cnt){
					case 3:
						$pin = $msg[2];
					break;
					case 4:
						$pin = $msg[3];
						$n = $msg[2];
					break;
					default:
						$this->processed($row->ID);
						continue;				
					break;			
				}
				$tujuan = $msg[1];
				$client = $this->getClient($row->HP,$pin);
				if(count($client)<1){
					$this->processed($row->ID);
					continue;				
				}
				$produk = $this->getProduk($cmd);
				if(count($produk)<1){
					$this->processed($row->ID);
					continue;				
				}				
				if($client->saldo<$produk->jual){
					$this->processed($row->ID);
					continue;				
				}
				if($this->cekTransaksi($client,$tujuan)<$n){
					$this->transaksi($client,$produk,$tujuan);
				}else{
					$param = array($tujuan,$n,$produk->kode,$tujuan,$n+1);
					$this->error($client,"sudah.transaksi");				
				}				
				$this->processed($row->ID);
				
			}	
		}
	
	}
	private function cekTransaksi($client,$tujuan){
		$q = "select count(*) from transaksi where date(waktu)=curdate() and client_id = ".$id." and tujuan = '$tujuan'";
		return $this->db->loadResult();	
	}
	private function strposa($haystack, $needles=array(), $offset=0) {
        	$chr = array();
        	foreach($needles as $needle) {
                	$res = strpos($haystack, $needle, $offset);
                	if ($res !== false) $chr[$needle] = $res;
        	}
        	if(empty($chr)) return false;
        	return min($chr);
	}
	private function updateSaldoVendor($produk){
		$q = "update vendor set saldo = saldo - ".$produk->beli." where nama = '".$produk->vendor."'";
		$this->db->setQuery($q);
		$this->db->query();	
	}
	private function updateSaldoClient($client,$produk){
		$q = "update client set saldo = saldo - ".$produk->jual." where id = ".$client->id;
		$this->db->setQuery($q);
		$this->db->query();	
	}
	private function transaksi($client,$produk,$tujuan){
		$q = "select a.* from vendor as a inner join produk as b on a.nama = b.vendor where b.id=".$produk->id;
		$this->db->setQuery($q);
		$vendor = $this->db->loadObject();
		if($vendor->saldo<$produk->jual){
			return false;		
		}
		$pattern = array("<NOHP>","<PIN>");
		$replace = array($tujuan,$vendor->pin);
		$kode = str_replace($pattern,$replace,$produk->kodeorder);
		$res = $this->ym->kirimPesan($vendor->ym,$kode);
		//$sukses = array("berhasil")
		//$stat = strposa(strtolower($res),$sukses);
		if($res){
			$q = "insert into transaksi_vendor(vendor,kode,harga,status,tujuan) values 
				('".$vendor->id."','".$produk->kode."',".$produk->beli.",'berhasil','$tujuan')";
			$this->db->setQuery($q);
			$this->db->query();
			$this->updateSaldoVendor($produk);
			$q = "insert into transaksi(client_id,kode,harga,status,tujuan) values 
				('".$client->id."','".$produk->kode."',".$produk->jual.",'berhasil','$tujuan')";
			$this->db->setQuery($q);
			$this->db->query();
			$this->updateSaldoClient($client,$produk);
		}
			
	}
	private function getProduk($kode){
		$q = "select * from produk where kode = '$kode'";
		$this->db->setQuery($q);
		$row = $this->db->loadObject();
		return $row;
	}
	private function getClient($hp,$pin){
		$q = "select a.*,b.hp from client as a inner join client_hp as b on a.id = b.client_id where b.hp = '$hp' and a.pin='$pin'";
		$this->db->setQuery($q);
		$row=$this->db->loadObject();	
		return $row;
	}
	private function getInboxAll($bool="false"){
		$q = "SELECT ID, SenderNumber as HP, TextDecoded as MSG FROM inbox WHERE processed = '$bool'";
		$this->db->setQuery($q); 
		$rows = $this->db->loadObjectList();
		return $rows;	
	}
	private function getInbox($id){
		$q = "SELECT ID, SenderNumber, TextDecoded FROM inbox WHERE ID = '$id'";
		$this->db->setQuery($q); 
		$rows = $this->db->loadObjectList();
		return $rows;		
	}
	private function processed($id){
		$q = "UPDATE inbox SET processed='true' WHERE ID='$id'";
		$this->db->setQuery($q);
		$this->db->query();
	}
	private function error($client,$kode,$array=array()){
		$q = "select keterangan from pesan where kode='".$kode"'";
		$this->db->setQuery($q);
		$err = vsprintf($this->db->loadResult(),$array);
		$q = "insert into outbox(DestinationNumber,TextDecoded) values ('".$client->hp."','$err')";
		$this->db->setQuery($q);
		$this->db->query();
	}
	private function log($log){
		$q = "insert into log(keterangan) values ('$log')";
		$this->db->setQuery($q);
		$this->db->query();
	}
}
?>
