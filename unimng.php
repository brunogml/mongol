<?php

function unicodemng (&$quoi){
   $quoi= ' '.$quoi;
 
   $quoi= str_replace("-aca", " ᠡᠴᠡ", $quoi);
   $quoi= str_replace("-ece", " ᠡᠴᠡ", $quoi);
   $quoi= str_replace("-a", "᠎ᠠ", $quoi);
   $quoi= str_replace("-i", " ᠢ", $quoi);
   $quoi= str_replace("-e", "᠎ᠡ", $quoi);
   $quoi= str_replace("-un", " ᠤᠨ", $quoi);
   $quoi= str_replace("-ün", " ᠤᠨ", $quoi);
   $quoi= str_replace("-u", " ᠤ", $quoi);
   $quoi= str_replace("-ü", " ᠤ", $quoi);

   $quoi= str_replace("-dür", "-dur", $quoi);
   $quoi= str_replace("-tür", "-tur", $quoi);
   $quoi= str_replace("-dur", " ᠳ᠋‍‍ᠤ‍ᠷ", $quoi);
   
   $quoi= str_replace("a", "ᠠ", $quoi);
   $quoi= str_replace("e", "ᠡ", $quoi);
   $quoi= str_replace("e", "ᠧ", $quoi);
   $quoi= str_replace("i", "ᠢ", $quoi);
   $quoi= str_replace("o", "ᠣ", $quoi);
   $quoi= str_replace("u", "ᠤ", $quoi);
   $quoi= str_replace("ö", "ᠥ", $quoi);
   $quoi= str_replace("ü", "ᠦ", $quoi);
   $quoi= str_replace("E", "ᠧ", $quoi);
   
   $quoi= str_replace("kh", "ᠻ", $quoi);
   $quoi= str_replace("ts", "ᠼ", $quoi);
   $quoi= str_replace("dz", "ᠽ", $quoi);
   $quoi= str_replace("ng", "ᠩ", $quoi);

   $quoi= str_replace("ke", "ᠭᠡ", $quoi);
   $quoi= str_replace("ki", "ᠭᠢ", $quoi);
   $quoi= str_replace("kü", "ᠭᠥ", $quoi);
   $quoi= str_replace("kö", "ᠭᠥ", $quoi);
   $quoi= str_replace("n", "ᠨ", $quoi);
   $quoi= str_replace("b", "ᠪ", $quoi);
   $quoi= str_replace("p", "ᠫ", $quoi);
   $quoi= str_replace("q", "ᠬ", $quoi);
   $quoi= str_replace("γ", "ᠭ", $quoi);
   $quoi= str_replace("g", "ᠭ", $quoi);
   $quoi= str_replace("m", "ᠮ", $quoi);
   $quoi= str_replace("l", "ᠯ", $quoi);
   $quoi= str_replace("s", "ᠰ", $quoi);
   $quoi= str_replace("S", "ᠱ", $quoi);
   $quoi= str_replace("š", "ᠱ", $quoi);
   $quoi= str_replace("t", "ᠲ", $quoi);
   $quoi= str_replace("d", "ᠳ", $quoi);
   $quoi= str_replace("c", "ᠴ", $quoi);
   $quoi= str_replace("č", "ᠴ", $quoi);
   $quoi= str_replace("j", "ᠵ", $quoi);
   $quoi= str_replace("ǰ", "ᠵ", $quoi);
   $quoi= str_replace("y", "ᠶ", $quoi);
   $quoi= str_replace("r", "ᠷ", $quoi);
   $quoi= str_replace("v", "ᠸ", $quoi);
   $quoi= str_replace("f", "ᠹ", $quoi);
   $quoi= str_replace("k", "ᠺ", $quoi);
   $quoi= str_replace("h", "ᠾ", $quoi);
   $quoi= str_replace("D", "ᢎ", $quoi);
   $quoi= str_replace("N", "ᢏ", $quoi);
   $quoi= str_replace("T", "ᢐ", $quoi);
   $quoi= str_replace("J", "ᠼ", $quoi);
   $quoi= str_replace("C", "ᡜ", $quoi);
   $quoi= str_replace("Y", "ᡛ", $quoi);
   $quoi= str_replace("M", "ᢀ", $quoi);
   $quoi= str_replace("K", "ᠻ", $quoi);

   $quoi= str_replace(" - ", " ~ ", $quoi);
   $quoi= str_replace("-", " ᠎", $quoi);

}