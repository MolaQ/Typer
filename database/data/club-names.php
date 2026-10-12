<?php

/*
 * Pula nazw prawdziwych klubów (piłka nożna, siatkówka, koszykówka; jedno- i dwuwyrazowe)
 * dla nowych kont tworzonych przez symulację (php artisan sim:season).
 * Tylko na czas testów: po sprawdzeniu systemu plik można usunąć.
 */

return [
    // --- Piłka nożna: Polska ---
    'Legia Warszawa', 'Wisła Kraków', 'Cracovia', 'Górnik Zabrze', 'Ruch Chorzów', 'Śląsk Wrocław', 'Pogoń Szczecin',
    'Widzew Łódź', 'ŁKS Łódź', 'Jagiellonia Białystok', 'Raków Częstochowa', 'Zagłębie Lubin', 'Korona Kielce',
    'Piast Gliwice', 'Stal Mielec', 'Wisła Płock', 'Warta Poznań', 'Motor Lublin', 'Radomiak Radom', 'Arka Gdynia',
    'Lechia Gdańsk', 'Odra Opole', 'Polonia Warszawa', 'Miedź Legnica', 'Znicz Pruszków', 'Puszcza Niepołomice',
    'Chrobry Głogów', 'GKS Tychy', 'GKS Katowice', 'Sandecja', 'Termalica', 'Olimpia Grudziądz', 'Stomil Olsztyn',
    'Wigry Suwałki', 'Resovia', 'Stal Rzeszów', 'Garbarnia Kraków', 'Hutnik Kraków', 'Elana Toruń', 'Zawisza Bydgoszcz',
    'Bałtyk Gdynia', 'Gwardia Koszalin', 'Lech Rypin', 'Unia Leszno', 'Olimpia Elbląg', 'Błękitni Stargard',
    'Kotwica Kołobrzeg', 'Pogoń Siedlce', 'Wisła Puławy', 'Avia Świdnik', 'Górnik Łęczna', 'Siarka Tarnobrzeg',
    'Unia Tarnów', 'Polonia Bytom', 'Szombierki Bytom', 'Odra Wodzisław', 'Rozwój Katowice', 'Skra Częstochowa',
    'Calisia Kalisz', 'KKS Kalisz', 'Sokół Kleczew', 'Nielba Wągrowiec', 'Obra Kościan', 'Lubuszanin Drezdenko',
    // --- Piłka nożna: Europa ---
    'Real Madrid', 'Atlético Madrid', 'Barcelona', 'Sevilla', 'Valencia', 'Villarreal', 'Real Betis', 'Real Sociedad',
    'Athletic Bilbao', 'Celta Vigo', 'Osasuna', 'Getafe', 'Espanyol', 'Mallorca', 'Girona', 'Rayo Vallecano',
    'Deportivo', 'Racing Santander', 'Real Zaragoza', 'Sporting Gijón', 'Real Oviedo', 'Málaga', 'Cádiz', 'Levante',
    'Manchester United', 'Manchester City', 'Liverpool', 'Arsenal', 'Chelsea', 'Tottenham', 'Everton', 'Newcastle United',
    'Aston Villa', 'West Ham', 'Leeds United', 'Nottingham Forest', 'Leicester City', 'Wolverhampton', 'Brighton',
    'Fulham', 'Crystal Palace', 'Brentford', 'Southampton', 'Sunderland', 'Middlesbrough', 'Burnley', 'Blackburn Rovers',
    'Sheffield United', 'Sheffield Wednesday', 'Ipswich Town', 'Norwich City', 'Derby County', 'Stoke City', 'Watford',
    'Bayern Monachium', 'Borussia Dortmund', 'Schalke', 'Werder Brema', 'Hamburger SV', 'Bayer Leverkusen', 'RB Lipsk',
    'Eintracht Frankfurt', 'VfB Stuttgart', 'Hertha Berlin', 'Union Berlin', 'FC Köln', 'Borussia Mönchengladbach',
    'Wolfsburg', 'Freiburg', 'Hoffenheim', 'Mainz', 'Augsburg', 'Bochum', 'Kaiserslautern', 'Nürnberg', 'Fortuna Düsseldorf',
    'St. Pauli', 'Hannover 96', 'Dynamo Drezno', 'Juventus', 'Inter Mediolan', 'AC Milan', 'AS Roma', 'Lazio', 'Napoli',
    'Fiorentina', 'Atalanta', 'Torino', 'Bologna', 'Genoa', 'Sampdoria', 'Udinese', 'Parma', 'Sassuolo', 'Verona',
    'Cagliari', 'Lecce', 'Empoli', 'Palermo', 'Bari', 'Brescia', 'Paris Saint-Germain', 'Olympique Marsylia',
    'Olympique Lyon', 'AS Monaco', 'Lille', 'Stade Rennes', 'Nantes', 'Bordeaux', 'Saint-Étienne', 'OGC Nice', 'Lens',
    'Montpellier', 'Strasbourg', 'Toulouse', 'Auxerre', 'Ajax', 'PSV Eindhoven', 'Feyenoord', 'AZ Alkmaar', 'FC Twente',
    'FC Utrecht', 'Vitesse', 'Heerenveen', 'FC Groningen', 'Sparta Rotterdam', 'Benfica', 'FC Porto', 'Sporting',
    'Sporting Braga', 'Vitória Guimarães', 'Boavista', 'Club Brugge', 'Anderlecht', 'Standard Liège', 'KRC Genk',
    'KAA Gent', 'Royal Antwerp', 'Celtic', 'Rangers', 'Aberdeen', 'Hearts', 'Hibernian', 'Dundee United', 'Galatasaray',
    'Fenerbahçe', 'Beşiktaş', 'Trabzonspor', 'Olympiakos', 'Panathinaikos', 'AEK Ateny', 'PAOK', 'Red Star', 'Partizan',
    'Dinamo Zagrzeb', 'Hajduk Split', 'Rijeka', 'Ferencváros', 'Sparta Praga', 'Slavia Praga', 'Viktoria Pilzno',
    'Baník Ostrava', 'Slovan Bratysława', 'Spartak Trnawa', 'Steaua Bukareszt', 'Dinamo Bukareszt', 'CFR Cluj',
    'Ludogorets', 'CSKA Sofia', 'Lewski Sofia', 'Szachtar Donieck', 'Dynamo Kijów', 'Dnipro', 'Zenit', 'Spartak Moskwa',
    'Rapid Wiedeń', 'Austria Wiedeń', 'RB Salzburg', 'Sturm Graz', 'LASK', 'Young Boys', 'FC Basel', 'Grasshoppers',
    'FC Zürich', 'Servette', 'FC Kopenhaga', 'Brøndby', 'FC Midtjylland', 'Malmö FF', 'AIK', 'Djurgården',
    'IFK Göteborg', 'Hammarby', 'Rosenborg', 'Molde', 'Bodø/Glimt', 'Vålerenga', 'HJK Helsinki', 'Shamrock Rovers',
    'Maccabi Hajfa', 'Hapoel Tel-Awiw', 'Žalgiris Wilno', 'Riga FC', 'Flora Tallinn', 'Sheriff Tyraspol', 'Qarabağ',
    // --- Piłka nożna: świat ---
    'Boca Juniors', 'River Plate', 'Independiente', 'Racing Club', 'San Lorenzo', 'Estudiantes', 'Vélez Sarsfield',
    'Newell’s', 'Rosario Central', 'Flamengo', 'Fluminense', 'Corinthians', 'Palmeiras', 'Santos', 'São Paulo',
    'Grêmio', 'Internacional', 'Vasco', 'Botafogo', 'Cruzeiro', 'Atlético Mineiro', 'Peñarol', 'Nacional',
    'Colo-Colo', 'Universidad Católica', 'Olimpia', 'Cerro Porteño', 'América', 'Chivas', 'Cruz Azul', 'Pumas',
    'Tigres', 'Monterrey', 'LA Galaxy', 'Inter Miami', 'Seattle Sounders', 'Portland Timbers', 'Toronto FC',
    'Al-Ahly', 'Zamalek', 'Esperance Tunis', 'Wydad Casablanca', 'Raja Casablanca', 'Kaizer Chiefs', 'Orlando Pirates',
    'Al-Hilal', 'Al-Nassr', 'Al-Ittihad', 'Urawa Reds', 'Kashima Antlers', 'Yokohama Marinos', 'Gamba Osaka',
    'Jeonbuk Motors', 'Ulsan Hyundai', 'Guangzhou', 'Shanghai Port', 'Melbourne Victory', 'Sydney FC', 'Western Sydney',
    // --- Siatkówka ---
    'Jastrzębski Węgiel', 'ZAKSA', 'Skra Bełchatów', 'Asseco Resovia', 'Projekt Warszawa', 'Trefl Gdańsk',
    'Aluron Zawiercie', 'Indykpol Olsztyn', 'Cuprum Lubin', 'Stal Nysa', 'Ślepsk Suwałki', 'Barkom Lwów',
    'Czarni Radom', 'Developres Rzeszów', 'Chemik Police', 'Budowlani Łódź', 'Grot Budowlani', 'Pałac Bydgoszcz',
    'ŁKS Commercecon', 'Perugia Volley', 'Itas Trentino', 'Lube Civitanova', 'Modena Volley', 'Piacenza',
    'Imoco Conegliano', 'Vero Volley', 'Novara', 'Zenit Kazań', 'Halkbank', 'Ziraat Bankası', 'VakıfBank',
    'Eczacıbaşı', 'Berlin Recycling', 'Friedrichshafen', 'Knack Roeselare', 'Tours VB', 'Chaumont',
    'Montpellier Volley', 'Sada Cruzeiro', 'Minas Tênis', 'Suntory Sunbirds', 'Panasonic Panthers',
    // --- Koszykówka ---
    'Anwil Włocławek', 'Śląsk Basket', 'Stal Ostrów', 'Trefl Sopot', 'Arka Basket', 'Legia Kosz', 'King Szczecin',
    'Czarni Słupsk', 'Start Lublin', 'Zastal', 'Twarde Pierniki', 'Spójnia Stargard', 'MKS Dąbrowa',
    'Asseco Gdynia', 'Turów Zgorzelec', 'Polpharma Starogard', 'AZS Koszalin', 'Real Basket', 'Barça Basket',
    'Baskonia', 'Valencia Basket', 'Unicaja', 'Joventut', 'Gran Canaria', 'Olympiacos BC', 'Panathinaikos BC',
    'Fenerbahçe Beko', 'Anadolu Efes', 'Maccabi Tel-Awiw', 'Virtus Bolonia', 'Olimpia Milano', 'Partizan Belgrad',
    'Crvena Zvezda', 'Žalgiris Kowno', 'ALBA Berlin', 'Bayern Basket', 'ASVEL', 'Monaco Basket', 'CSKA Moskwa',
    'Cedevita Olimpija', 'Bourg Basket', 'Boston Celtics', 'LA Lakers', 'Chicago Bulls', 'Golden State',
    'Miami Heat', 'Spurs', 'Knicks', 'Brooklyn Nets', 'Philadelphia 76ers', 'Toronto Raptors',
    'Denver Nuggets', 'Dallas Mavericks', 'Phoenix Suns', 'Utah Jazz', 'Detroit Pistons', 'Indiana Pacers',
    'Milwaukee Bucks', 'Cleveland Cavaliers', 'Atlanta Hawks', 'Orlando Magic', 'Charlotte Hornets', 'Houston Rockets',
    'Memphis Grizzlies', 'Sacramento Kings', 'Trail Blazers', 'Minnesota Timberwolves', 'Oklahoma Thunder',
    'Washington Wizards', 'Pelicans', 'LA Clippers',
];
