<?php
/**
 * Populate courts database with pickleball courts from
 * Orange County, Los Angeles County, and San Diego County.
 * Uses Google Maps Geocoding API for lat/lng and county.
 * Skips duplicates by name+city.
 * DELETE AFTER USE.
 */
set_time_limit(600); // 10 minutes
header('Content-Type: application/json');
require_once __DIR__ . '/db_config.php';

$results = ['inserted' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

// All courts to insert
$courts = [
    // ═══════════════════════════════════════════════
    // ORANGE COUNTY
    // ═══════════════════════════════════════════════
    ["name"=>"Anaheim Tennis and Pickleball Center","address"=>"975 S State College Blvd","city"=>"Anaheim","state"=>"CA"],
    ["name"=>"Twila Reid Park","address"=>"3100 W Orange Ave","city"=>"Anaheim","state"=>"CA"],
    ["name"=>"Arovista Park","address"=>"500 W Imperial Hwy","city"=>"Brea","state"=>"CA"],
    ["name"=>"Whitaker Park","address"=>"8412 California St","city"=>"Buena Park","state"=>"CA"],
    ["name"=>"Buena Park Community Gymnasium","address"=>"6931 Orangethorpe Ave","city"=>"Buena Park","state"=>"CA"],
    ["name"=>"Costa Mesa Downtown Recreation Center","address"=>"1860 Anaheim Ave","city"=>"Costa Mesa","state"=>"CA"],
    ["name"=>"Tanager Park","address"=>"1780 Hummingbird Dr","city"=>"Costa Mesa","state"=>"CA"],
    ["name"=>"Lexington Park","address"=>"4600 W Cerritos Ave","city"=>"Cypress","state"=>"CA"],
    ["name"=>"Cypress College","address"=>"9200 Valley View St","city"=>"Cypress","state"=>"CA"],
    ["name"=>"Arnold Cypress Park","address"=>"8611 Watson Ave","city"=>"Cypress","state"=>"CA"],
    ["name"=>"Del Obispo Community Park","address"=>"34052 Del Obispo St","city"=>"Dana Point","state"=>"CA"],
    ["name"=>"Los Cab Sports Village","address"=>"17272 Newhope St","city"=>"Fountain Valley","state"=>"CA"],
    ["name"=>"Fountain Valley Recreation Center & Sports Park","address"=>"16400 Brookhurst St","city"=>"Fountain Valley","state"=>"CA"],
    ["name"=>"Fullerton Tennis Center","address"=>"110 E Valencia Mesa Dr","city"=>"Fullerton","state"=>"CA"],
    ["name"=>"Fullerton Community Center Gym","address"=>"340 W Commonwealth Ave","city"=>"Fullerton","state"=>"CA"],
    ["name"=>"Union Pacific Park","address"=>"601 N Union Ave","city"=>"Fullerton","state"=>"CA"],
    ["name"=>"Chapman Sports Complex","address"=>"12572 Chapman Ave","city"=>"Garden Grove","state"=>"CA"],
    ["name"=>"Garden Grove Sports & Recreation Center","address"=>"13641 Deodara Dr","city"=>"Garden Grove","state"=>"CA"],
    ["name"=>"Golden West College Pickleball Courts","address"=>"15744 Goldenwest St","city"=>"Huntington Beach","state"=>"CA"],
    ["name"=>"Worthy Park","address"=>"1831 17th St","city"=>"Huntington Beach","state"=>"CA"],
    ["name"=>"Edison Park","address"=>"21377 Magnolia St","city"=>"Huntington Beach","state"=>"CA"],
    ["name"=>"Portola Springs Community Park","address"=>"900 Tomato Springs","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Heritage Community Park","address"=>"14301 Yale Ave","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Mike Ward Community Park","address"=>"20 Lake Rd","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Los Olivos Community Park","address"=>"101 Alfonso","city"=>"Irvine","state"=>"CA"],
    ["name"=>"San Carlo Park","address"=>"15 San Carlo","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Knollcrest Park","address"=>"2069 Knollcrest","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Merage Jewish Community Center","address"=>"1 Federation Way","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Racquet Club of Irvine","address"=>"5 Ethel Coplen Way","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Turtle Rock Community Park","address"=>"1 Sunnyhill","city"=>"Irvine","state"=>"CA"],
    ["name"=>"Alta Laguna Park","address"=>"3300 Alta Laguna Blvd","city"=>"Laguna Beach","state"=>"CA"],
    ["name"=>"Laguna Hills Community Center and Sports Complex","address"=>"25555 Alicia Pkwy","city"=>"Laguna Hills","state"=>"CA"],
    ["name"=>"Laguna Niguel Regional Park","address"=>"28241 La Paz Rd","city"=>"Laguna Niguel","state"=>"CA"],
    ["name"=>"Crown Valley Pickleball Courts","address"=>"29292 Crown Valley Pkwy","city"=>"Laguna Niguel","state"=>"CA"],
    ["name"=>"Laguna Woods Village Pickleball Courts","address"=>"24122 Moulton Pkwy","city"=>"Laguna Woods","state"=>"CA"],
    ["name"=>"iPickle La Habra","address"=>"351 S Euclid St","city"=>"La Habra","state"=>"CA"],
    ["name"=>"Central Park","address"=>"7821 Walker St","city"=>"La Palma","state"=>"CA"],
    ["name"=>"Pickleball Haven","address"=>"25871 Atlantic Ocean Dr","city"=>"Lake Forest","state"=>"CA"],
    ["name"=>"Ridge Route Courts","address"=>"22921 Ridge Route Dr","city"=>"Lake Forest","state"=>"CA"],
    ["name"=>"Portola Oaks Courts","address"=>"1004 Portola Oaks Dr","city"=>"Lake Forest","state"=>"CA"],
    ["name"=>"Laurel Park","address"=>"10862 Bloomfield St","city"=>"Los Alamitos","state"=>"CA"],
    ["name"=>"Oak Middle School","address"=>"10821 Oak St","city"=>"Los Alamitos","state"=>"CA"],
    ["name"=>"Felipe Tennis & Recreation Center","address"=>"27781 Felipe Rd","city"=>"Mission Viejo","state"=>"CA"],
    ["name"=>"Sendero Field Park","address"=>"28642 Oso Pkwy","city"=>"Mission Viejo","state"=>"CA"],
    ["name"=>"Sierra Recreation Center","address"=>"25162 Marta","city"=>"Mission Viejo","state"=>"CA"],
    ["name"=>"The Tennis & Pickleball Club at Newport Beach","address"=>"11 Clubhouse Dr","city"=>"Newport Beach","state"=>"CA"],
    ["name"=>"Bonita Canyon Sports Park","address"=>"1990 Ford Rd","city"=>"Newport Beach","state"=>"CA"],
    ["name"=>"Newport Coast Community Center","address"=>"6401 San Joaquin Hills Rd","city"=>"Newport Beach","state"=>"CA"],
    ["name"=>"Grijalva Park Sports Center","address"=>"368 N Prospect St","city"=>"Orange","state"=>"CA"],
    ["name"=>"Tuffree Hill Park","address"=>"2101 Tuffree Blvd","city"=>"Placentia","state"=>"CA"],
    ["name"=>"Altisima Pickleball Courts","address"=>"30082 Melinda Rd","city"=>"Rancho Santa Margarita","state"=>"CA"],
    ["name"=>"San Gorgonio Park","address"=>"2916 Via San Gorgonio","city"=>"San Clemente","state"=>"CA"],
    ["name"=>"San Luis Rey Park","address"=>"2709 Calle Del Comercio","city"=>"San Clemente","state"=>"CA"],
    ["name"=>"Steed Memorial Park","address"=>"247 Avenida La Pata","city"=>"San Clemente","state"=>"CA"],
    ["name"=>"San Juan Capistrano Pickleball & Tennis Courts","address"=>"31480 Camino Capistrano","city"=>"San Juan Capistrano","state"=>"CA"],
    ["name"=>"Cook La Novia Park","address"=>"25862 Camino Del Avion","city"=>"San Juan Capistrano","state"=>"CA"],
    ["name"=>"McFadden Institute of Technology","address"=>"2701 S Raitt St","city"=>"Santa Ana","state"=>"CA"],
    ["name"=>"Seal Beach Tennis & Pickleball Center","address"=>"3900 Lampson Ave","city"=>"Seal Beach","state"=>"CA"],
    ["name"=>"Marina Park","address"=>"151 Marina Dr","city"=>"Seal Beach","state"=>"CA"],
    ["name"=>"Tustin Pickleball Courts","address"=>"1402 Sycamore Ave","city"=>"Tustin","state"=>"CA"],
    ["name"=>"Sigler Park","address"=>"7200 Plaza St","city"=>"Westminster","state"=>"CA"],
    ["name"=>"Las Palomas Tennis Park","address"=>"20550 Paseo de las Palomas","city"=>"Yorba Linda","state"=>"CA"],
    ["name"=>"West Coast Pickleball","address"=>"23061 Savi Ranch Pkwy","city"=>"Yorba Linda","state"=>"CA"],
    ["name"=>"ClubSport Aliso Viejo","address"=>"50 Enterprise","city"=>"Aliso Viejo","state"=>"CA"],

    // ═══════════════════════════════════════════════
    // LOS ANGELES COUNTY
    // ═══════════════════════════════════════════════
    ["name"=>"Griffith Park Recreation Center","address"=>"3401 Riverside Dr","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Rancho Park","address"=>"10460 W Pico Blvd","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Balboa Sports Complex","address"=>"17015 Burbank Blvd","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Wolf & Bear Pickleball","address"=>"14911 Calvert St","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"PIKL Los Angeles","address"=>"639 S La Brea Ave","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Pickle Alley Los Angeles","address"=>"350 S Anderson St","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Xisle Sports","address"=>"1118 S La Cienega Blvd","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Westchester LA Pickleball","address"=>"7000 W Manchester Ave","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Tarzana Recreation Center","address"=>"5655 Vanalden Ave","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Woodland Hills Recreation Center","address"=>"5858 Shoup Ave","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Encino Community Center","address"=>"4935 Balboa Blvd","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Northridge Recreation Center","address"=>"18300 Lemarsh St","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Venice Beach Pickleball Courts","address"=>"1800 Ocean Front Walk","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Pan Pacific Recreation Center","address"=>"7600 Beverly Blvd","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Sherman Oaks Tennis & Pickleball","address"=>"14201 Huston St","city"=>"Los Angeles","state"=>"CA"],
    ["name"=>"Santa Monica Pickleball Center","address"=>"1330 4th St","city"=>"Santa Monica","state"=>"CA"],
    ["name"=>"Memorial Park","address"=>"1401 Olympic Blvd","city"=>"Santa Monica","state"=>"CA"],
    ["name"=>"Plummer Park","address"=>"1200 N Vista St","city"=>"West Hollywood","state"=>"CA"],
    ["name"=>"West Hollywood Park","address"=>"647 N San Vicente Blvd","city"=>"West Hollywood","state"=>"CA"],
    ["name"=>"Roxbury Park Tennis & Pickleball Courts","address"=>"401 S Roxbury Dr","city"=>"Beverly Hills","state"=>"CA"],
    ["name"=>"Syd Kronenthal Park","address"=>"3459 McManus Ave","city"=>"Culver City","state"=>"CA"],
    ["name"=>"Veterans Park","address"=>"101 E 28th St","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Marina Vista Park","address"=>"5355 E Eliot St","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"El Dorado Tennis Center","address"=>"2800 N Studebaker Rd","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Heartwell Park","address"=>"6700 E Carson St","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Whaley Park","address"=>"5620 Atherton St","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Scherer Park","address"=>"4600 Long Beach Blvd","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Silverado Park","address"=>"1545 W 31st St","city"=>"Long Beach","state"=>"CA"],
    ["name"=>"Scholl Canyon Golf & Tennis Club","address"=>"3800 E Glenoaks Blvd","city"=>"Glendale","state"=>"CA"],
    ["name"=>"Pacific Community Center & Park","address"=>"501 S Pacific Ave","city"=>"Glendale","state"=>"CA"],
    ["name"=>"Smash Dink","address"=>"4400 San Fernando Rd","city"=>"Glendale","state"=>"CA"],
    ["name"=>"Robinson Park","address"=>"1081 N Fair Oaks Ave","city"=>"Pasadena","state"=>"CA"],
    ["name"=>"Vina Vieja Park","address"=>"3026 E Orange Grove Blvd","city"=>"Pasadena","state"=>"CA"],
    ["name"=>"Victory Park","address"=>"2575 Paloma St","city"=>"Pasadena","state"=>"CA"],
    ["name"=>"Washington Park","address"=>"700 E Washington Blvd","city"=>"Pasadena","state"=>"CA"],
    ["name"=>"Villa Parke Community Center","address"=>"363 E Villa St","city"=>"Pasadena","state"=>"CA"],
    ["name"=>"iPickle Arroyo Seco Racquet Club","address"=>"920 Lohman Ln","city"=>"South Pasadena","state"=>"CA"],
    ["name"=>"Burbank Tennis Center","address"=>"3715 Pacific Ave","city"=>"Burbank","state"=>"CA"],
    ["name"=>"Olive Recreation Center","address"=>"1111 W Olive Ave","city"=>"Burbank","state"=>"CA"],
    ["name"=>"iPickle Arcadia","address"=>"1420 S 6th Ave","city"=>"Arcadia","state"=>"CA"],
    ["name"=>"Sur La Brea Park","address"=>"3855 W 242nd St","city"=>"Torrance","state"=>"CA"],
    ["name"=>"Walteria Park","address"=>"2400 Jefferson St","city"=>"Torrance","state"=>"CA"],
    ["name"=>"Charles H. Wilson Park","address"=>"2200 Crenshaw Blvd","city"=>"Torrance","state"=>"CA"],
    ["name"=>"South Bay Tennis & Pickleball Center","address"=>"25924 Rolling Hills Rd","city"=>"Torrance","state"=>"CA"],
    ["name"=>"Alta Vista Park","address"=>"801 Camino Real","city"=>"Redondo Beach","state"=>"CA"],
    ["name"=>"Franklin Park","address"=>"1900 Voorhees Ave","city"=>"Redondo Beach","state"=>"CA"],
    ["name"=>"Manhattan Heights Park","address"=>"1600 Manhattan Beach Blvd","city"=>"Manhattan Beach","state"=>"CA"],
    ["name"=>"Live Oak Park","address"=>"1901 Valley Dr","city"=>"Manhattan Beach","state"=>"CA"],
    ["name"=>"Kelly Courts","address"=>"861 Valley Dr","city"=>"Hermosa Beach","state"=>"CA"],
    ["name"=>"Peninsula Racquet Club","address"=>"30850 Hawthorne Blvd","city"=>"Rancho Palos Verdes","state"=>"CA"],
    ["name"=>"Point Vicente County Park","address"=>"30940 Hawthorne Blvd","city"=>"Rancho Palos Verdes","state"=>"CA"],
    ["name"=>"Hemingway Park","address"=>"700 E Gardena Blvd","city"=>"Carson","state"=>"CA"],
    ["name"=>"PowerPlay Pickleball","address"=>"19401 S Main St","city"=>"Carson","state"=>"CA"],
    ["name"=>"Darby Park","address"=>"3400 W Arbor Vitae St","city"=>"Inglewood","state"=>"CA"],
    ["name"=>"California Smash","address"=>"815 N Nash St","city"=>"El Segundo","state"=>"CA"],
    ["name"=>"Independence Park","address"=>"12334 Bellflower Blvd","city"=>"Downey","state"=>"CA"],
    ["name"=>"Liberty Park","address"=>"19211 Studebaker Rd","city"=>"Cerritos","state"=>"CA"],
    ["name"=>"Don Knabe Community Regional Park","address"=>"19700 Bloomfield Ave","city"=>"Cerritos","state"=>"CA"],
    ["name"=>"La Mirada Community Regional Park","address"=>"13701 Adelfa Dr","city"=>"La Mirada","state"=>"CA"],
    ["name"=>"Bolivar Park","address"=>"3300 Del Amo Blvd","city"=>"Lakewood","state"=>"CA"],
    ["name"=>"Mayfair Park","address"=>"5720 Clark Ave","city"=>"Lakewood","state"=>"CA"],
    ["name"=>"Norwalk Arts & Sports Complex","address"=>"13000 Clarkdale Ave","city"=>"Norwalk","state"=>"CA"],
    ["name"=>"Hollenbeck Park","address"=>"1250 N Hollenbeck Ave","city"=>"Covina","state"=>"CA"],
    ["name"=>"Cameron Community Center","address"=>"1305 E Cameron Ave","city"=>"West Covina","state"=>"CA"],
    ["name"=>"Palomares Park Community Center","address"=>"499 E Arrow Hwy","city"=>"Pomona","state"=>"CA"],
    ["name"=>"Maple Hill Park","address"=>"1355 Maple Hill Rd","city"=>"Diamond Bar","state"=>"CA"],
    ["name"=>"Wheeler Park","address"=>"626 Vista Dr","city"=>"Claremont","state"=>"CA"],
    ["name"=>"Las Flores Park","address"=>"3175 Bolling Ave","city"=>"La Verne","state"=>"CA"],
    ["name"=>"Via Verde Country Club","address"=>"1410 Avenida Entrada","city"=>"San Dimas","state"=>"CA"],
    ["name"=>"Ruth R. Caruthers Park","address"=>"10500 Flora Vista St","city"=>"Bellflower","state"=>"CA"],
    ["name"=>"Calabasas Pickleball Club","address"=>"5155 Old Scandia Ln","city"=>"Calabasas","state"=>"CA"],
    ["name"=>"Bouquet Canyon Park","address"=>"28127 Wellston Dr","city"=>"Santa Clarita","state"=>"CA"],
    ["name"=>"Vista Canyon Park","address"=>"16950 Lost Canyon Rd","city"=>"Santa Clarita","state"=>"CA"],
    ["name"=>"Santa Clarita Sports Complex","address"=>"20850 Centre Pointe Pkwy","city"=>"Santa Clarita","state"=>"CA"],
    ["name"=>"The Paseo Club","address"=>"27650 Dickason Dr","city"=>"Santa Clarita","state"=>"CA"],
    ["name"=>"Sgt. Steve Owen Memorial Park","address"=>"43063 10th St W","city"=>"Lancaster","state"=>"CA"],
    ["name"=>"William J. McAdam Park","address"=>"38115 30th St E","city"=>"Palmdale","state"=>"CA"],
    ["name"=>"Marie Kerr Park","address"=>"39700 30th St W","city"=>"Palmdale","state"=>"CA"],
    ["name"=>"Monrovia Recreation Park","address"=>"620 S Shamrock Ave","city"=>"Monrovia","state"=>"CA"],

    // ═══════════════════════════════════════════════
    // SAN DIEGO COUNTY
    // ═══════════════════════════════════════════════
    ["name"=>"Barnes Tennis Center","address"=>"4490 W Point Loma Blvd","city"=>"San Diego","state"=>"CA"],
    ["name"=>"San Diego Municipal Gymnasium","address"=>"2111 Pan American Plaza","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Balboa Park Activity Center","address"=>"2145 Park Blvd","city"=>"San Diego","state"=>"CA"],
    ["name"=>"North Park Rec Center","address"=>"4044 Idaho St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Ocean Beach Recreation Center","address"=>"4726 Santa Monica Ave","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Robb Field Pickleball Courts","address"=>"2525 Bacon St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Pacific Beach Recreation Center","address"=>"1405 Diamond St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"San Diego Pickleball (Mission Bay)","address"=>"1775 E Mission Bay Dr","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Cypress Canyon Park","address"=>"11490 Cypress Canyon Rd","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Carmel Valley Recreation Center","address"=>"3777 Townsgate Dr","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Scripps Ranch Recreation Center","address"=>"11464 Blue Cypress Dr","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Rancho Bernardo-Glassman Recreation Center","address"=>"18448 W Bernardo Dr","city"=>"San Diego","state"=>"CA"],
    ["name"=>"North Clairemont Recreation Center","address"=>"4421 Bannock Ave","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Pacific Highlands Ranch Recreation Center","address"=>"5977 Village Center Loop Rd","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Golden Hill Recreation Center","address"=>"1315 26th St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Nobel Recreation Center","address"=>"8810 Judicial Dr","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Black Mountain Multipurpose Center","address"=>"9353 Oviedo St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Kearny Mesa Recreation Center","address"=>"3170 Armstrong St","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Ocean Air Recreation Center","address"=>"4770 Fairport Way","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Grand Del Mar Resort","address"=>"5300 Grand Del Mar Ct","city"=>"San Diego","state"=>"CA"],
    ["name"=>"Bobby Riggs Racket & Paddle Club","address"=>"875 Santa Fe Dr","city"=>"Encinitas","state"=>"CA"],
    ["name"=>"Poinsettia Community Park","address"=>"6600 Hidden Valley Rd","city"=>"Carlsbad","state"=>"CA"],
    ["name"=>"Pine Avenue Community Center","address"=>"799 Pine Ave","city"=>"Carlsbad","state"=>"CA"],
    ["name"=>"Calavera Hills Community Center","address"=>"2997 Glasgow Dr","city"=>"Carlsbad","state"=>"CA"],
    ["name"=>"Stagecoach Community Center","address"=>"3420 Camino De Los Coches","city"=>"Carlsbad","state"=>"CA"],
    ["name"=>"Pickleball Club of Carlsbad","address"=>"2561 El Camino Real","city"=>"Carlsbad","state"=>"CA"],
    ["name"=>"Melba Bishop Park","address"=>"5306 N River Rd","city"=>"Oceanside","state"=>"CA"],
    ["name"=>"Junior Seau Beach Community Center","address"=>"300 The Strand N","city"=>"Oceanside","state"=>"CA"],
    ["name"=>"Mackenzie Creek Park","address"=>"2775 MacKenzie Creek Rd","city"=>"Chula Vista","state"=>"CA"],
    ["name"=>"Better at Pickleball","address"=>"1163 Tierra Del Rey","city"=>"Chula Vista","state"=>"CA"],
    ["name"=>"Coronado Cays Park","address"=>"100 Coronado Cays Blvd","city"=>"Coronado","state"=>"CA"],
    ["name"=>"Surf & Turf Tennis Center","address"=>"1505 Lomas Santa Fe Dr","city"=>"Solana Beach","state"=>"CA"],
    ["name"=>"Kennedy Park","address"=>"1011 E Main St","city"=>"El Cajon","state"=>"CA"],
    ["name"=>"La Mesita Park","address"=>"8855 Dallas St","city"=>"La Mesa","state"=>"CA"],
    ["name"=>"MacArthur Park","address"=>"4975 Memorial Dr","city"=>"La Mesa","state"=>"CA"],
    ["name"=>"Harry Griffen Park","address"=>"9550 Milden St","city"=>"La Mesa","state"=>"CA"],
    ["name"=>"Kit Carson Park","address"=>"3333 Bear Valley Pkwy","city"=>"Escondido","state"=>"CA"],
    ["name"=>"Washington Park","address"=>"501 N Rose St","city"=>"Escondido","state"=>"CA"],
    ["name"=>"East Valley Community Center","address"=>"2245 E Valley Pkwy","city"=>"Escondido","state"=>"CA"],
    ["name"=>"Poway Community Center","address"=>"14343 Silverset St","city"=>"Poway","state"=>"CA"],
    ["name"=>"Thibodo Park","address"=>"1150 Lupine Hills Dr","city"=>"Vista","state"=>"CA"],
    ["name"=>"Brengle Terrace Park","address"=>"1200 Vale Terrace Dr","city"=>"Vista","state"=>"CA"],
    ["name"=>"Innovation Park","address"=>"1151 Armorlite Dr","city"=>"San Marcos","state"=>"CA"],
    ["name"=>"Woodland Park","address"=>"671 Woodland Pkwy","city"=>"San Marcos","state"=>"CA"],
    ["name"=>"Connors Park","address"=>"320 W San Marcos Blvd","city"=>"San Marcos","state"=>"CA"],
    ["name"=>"Big Rock Park","address"=>"8125 Arlette St","city"=>"Santee","state"=>"CA"],
    ["name"=>"Mast Park","address"=>"8790 Mast Blvd","city"=>"Santee","state"=>"CA"],
    ["name"=>"Town Center Community Park West","address"=>"9409 Cuyamaca St","city"=>"Santee","state"=>"CA"],
    ["name"=>"Fallbrook Community Center","address"=>"341 Heald Ln","city"=>"Fallbrook","state"=>"CA"],
    ["name"=>"Ingold Sports Park","address"=>"2551 Olive Hill Rd","city"=>"Fallbrook","state"=>"CA"],
    ["name"=>"The HUB Pickleball","address"=>"9545 Campo Rd","city"=>"Spring Valley","state"=>"CA"],
    ["name"=>"Imperial Beach Sports Park","address"=>"425 Imperial Beach Blvd","city"=>"Imperial Beach","state"=>"CA"],
    ["name"=>"Lemon Grove Recreation Center","address"=>"3131 School Ln","city"=>"Lemon Grove","state"=>"CA"],
];

$total = count($courts);
$i = 0;

foreach ($courts as $court) {
    $i++;
    $name = $court['name'];
    $city = $court['city'];

    // Check for duplicate (same name in same city)
    $existing = dbGetRow(
        "SELECT id FROM courts WHERE name = ? AND city = ?",
        [$name, $city]
    );

    if ($existing) {
        $results['skipped']++;
        $results['details'][] = "SKIP: {$name} ({$city}) - already exists";
        continue;
    }

    // Geocode the address to get lat/lng and county
    $address = $court['address'] . ', ' . $city . ', ' . $court['state'];
    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
         . urlencode($address)
         . '&key=' . GOOGLE_MAPS_API_KEY;

    $response = @file_get_contents($url);
    $data = json_decode($response, true);

    $lat = null;
    $lng = null;
    $county = null;

    if (!empty($data['results'])) {
        $geo = $data['results'][0];
        $lat = $geo['geometry']['location']['lat'] ?? null;
        $lng = $geo['geometry']['location']['lng'] ?? null;

        foreach ($geo['address_components'] ?? [] as $comp) {
            if (in_array('administrative_area_level_2', $comp['types'])) {
                $county = $comp['long_name'];
                break;
            }
        }
    }

    if ($lat && $lng) {
        $id = dbInsert(
            "INSERT INTO courts (name, address, city, state, county, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$name, $court['address'], $city, $court['state'], $county, $lat, $lng]
        );

        if ($id) {
            $results['inserted']++;
            $results['details'][] = "OK [{$i}/{$total}]: {$name} ({$city}) -> {$county} [{$lat},{$lng}]";
        } else {
            $results['failed']++;
            $results['details'][] = "FAIL: {$name} ({$city}) - DB insert error";
        }
    } else {
        // Insert without geocoding
        $id = dbInsert(
            "INSERT INTO courts (name, address, city, state) VALUES (?, ?, ?, ?)",
            [$name, $court['address'], $city, $court['state']]
        );
        $results['failed']++;
        $results['details'][] = "NOGEO [{$i}/{$total}]: {$name} ({$city}) - geocoding failed, inserted without coords";
    }

    // Rate limit: 50ms between requests
    usleep(50000);
}

$results['total_courts_after'] = dbGetRow("SELECT COUNT(*) as c FROM courts")['c'];
$results['counties_after'] = dbGetAll("SELECT county, COUNT(*) as count FROM courts WHERE county IS NOT NULL GROUP BY county ORDER BY county");

echo json_encode($results, JSON_PRETTY_PRINT);
