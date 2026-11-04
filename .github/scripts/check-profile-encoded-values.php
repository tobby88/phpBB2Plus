<?php
// Includes the actual public/ACP form-controller fixture and request bootstrap.
require __DIR__ . '/check-custom-profile-forms.php';
$encoded_cases = 0;
foreach (array(TEXT_FIELD, TEXTAREA) as $type) {
    foreach (array(array(1,'&','&'),array(2,'&<Z','&<'),array(2,'&amp;','&a'),array(2,"'\"Z","'\""),array(2,'ä😀Z','ä😀'),array(1,'0Z','0'),array(2,'\\&Z','\\&'),array(1,"\xc3", "\xef\xbf\xbd")) as $case) {
        list($maximum,$raw,$expected) = $case;
        $field=array('field_name'=>'Encoded fixture','field_column'=>'encoded_fixture','field_type'=>$type,'text_field_maxlen'=>$maximum,'text_area_maxlen'=>$maximum);
        for ($hop=0;$hop<3;$hop++) {
            profile_fixture_request(array('encoded_fixture'=>$raw));
            $stored=phpbb_profile_field_input($field,$_POST);
            cpf_check($stored===cpf_encode($expected),'Complete encoded input '.$type.'/'.$encoded_cases.'/'.$hop);
            $raw=cpf_read(phpbb_profile_field_form_control($field,$stored),'encoded_fixture',$type);
            cpf_check($raw===$expected,'Exact visible character round trip '.$type.'/'.$encoded_cases.'/'.$hop);
        }
        cpf_check(phpbb_profile_new_account_values(array($field),array('encoded_fixture'=>$stored))===array('encoded_fixture'=>$stored),'New-account exact stored value');
        $encoded_cases++;
    }
    $maximum=$type===TEXT_FIELD?255:60000;
    foreach (array('&','"','😀') as $character) {
        $field=array('field_name'=>'Boundary','field_column'=>'encoded_fixture','field_type'=>$type,'text_field_maxlen'=>$maximum,'text_area_maxlen'=>$maximum);
        $raw=str_repeat($character,$maximum);profile_fixture_request(array('encoded_fixture'=>$raw.'Z'));
        $stored=phpbb_profile_field_input($field,$_POST);
        cpf_check($stored===cpf_encode($raw)&&phpbb_profile_encoded_value_valid($stored,$maximum),'Full encoded boundary '.$type.'/'.$character);
        cpf_check(!phpbb_profile_encoded_value_valid($stored.'Z',$maximum),'One character above limit rejected');
        cpf_check(cpf_read(phpbb_profile_field_form_control($field,$stored),'encoded_fixture',$type)===$raw,'Boundary form round trip');
        $encoded_cases++;
    }
}
$options=$raw_options=array();
for($i=0;$i<100;$i++){$raw_options[]=str_repeat('"',250).sprintf('%05d',$i);$options[]=cpf_encode($raw_options[$i]);}
$field=array('field_name'=>'Atomic selections','field_column'=>'encoded_choices','field_type'=>CHECKBOX,'checkbox_values'=>implode(',',$options));
profile_fixture_request(array('encoded_choices'=>$raw_options));$stored=phpbb_profile_field_input($field,$_POST);
cpf_check($stored===implode(',',$options)&&strlen($stored)>60000,'Checkbox options remain whole above old encoded byte limit');
cpf_check(cpf_read(phpbb_profile_field_form_control($field,$stored),'encoded_choices',CHECKBOX)===$raw_options,'All complete checkbox selections round trip');$encoded_cases++;
$field['field_type']=RADIO;$field['radio_button_values']=$options[0];profile_fixture_request(array('encoded_choices'=>$raw_options[0]));
cpf_check(phpbb_profile_field_input($field,$_POST)===$options[0],'Complete encoded radio option');$encoded_cases++;
foreach(array('&amp;','&#38;','&#x26;','0','')as$old){$field=array('field_name'=>'Legacy','field_column'=>'legacy_encoded','field_type'=>TEXT_FIELD,'text_field_maxlen'=>1,'text_field_default'=>$old);cpf_check(phpbb_profile_new_account_values(array($field))===array('legacy_encoded'=>$old),'Legacy entity spelling/default bytes preserved');$encoded_cases++;}
foreach(array("bad\0value", "\xff", str_repeat('&quot;',60001))as$bad){cpf_check(!phpbb_profile_encoded_value_valid($bad,60000),'Invalid/oversized encoded value refused');$encoded_cases++;}
echo 'Encoded custom profiles: '.$encoded_cases." raw-character boundaries, actual forms, atomic selections and preserved defaults PASS.\n";
