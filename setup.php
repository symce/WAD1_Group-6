<?php
/**
 * setup.php - creates the tables (if missing) and seeds the demo data.
 *
 *   CLI:      php setup.php            (safe to run again: nothing is wiped)
 *             php setup.php --reset    (CLI only: deletes the database file and rebuilds it)
 *   Browser:  http://localhost:8000/setup.php
 *
 * Data below is copied VERBATIM from the original frontend (initialTribes, initialWeaves, initialUsers).
 * Each table is only seeded when it is empty. The two demo accounts are hashed with password_hash().
 */
declare(strict_types=1);

require __DIR__ . '/config/db.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
function say(string $m): void
{
    echo $m, PHP_EOL;
}

$initialTribes = [
    ['tribe_id' => 1, 'tribe_name' => 'T\'boli', 'location' => 'Lake Sebu, South Cotabato (Mindanao & Sulu)', 'description' => 'Renowned dream-weavers of the southern Mindanao highlands who craft sacred T\'nalak abaca cloth inspired by visions from Fu Dalu.', 'history' => 'Inhabiting the upland valleys surrounding Lake Sebu, Lake Siloton, and Lake Lahit in South Cotabato, the T\'boli have sustained an unbroken oral, musical, and textile heritage across centuries.', 'cultural_background' => 'T\'boli cosmology centers on harmony between the natural world and guardian spirits. Community life revolves around epic chanting (Tudbulul), brass casting, beadwork, and sacred backstrap weaving.', 'traditional_practices' => 'Master women weavers (Be T\'nalak) harvest mature wild abaca, strip and hand-comb fibers, and tie intricate resist knots (ikat) entirely from mental blueprints revealed in dreams.', 'cultural_significance' => 'T\'nalak serves as ceremonial currency in marriage celebrations, a healing blanket for the sick, and an emblem of spiritual blessing guarded under strict cultural respect.', 'image' => 'Tnalak.png'],
    ['tribe_id' => 2, 'tribe_name' => 'Ifugao', 'location' => 'Banaue & Kiangan, Cordillera Central (Luzon)', 'description' => 'Highland custodians of the Cordillera rice terraces whose Inabel and Gammit textiles chronicle ancestral lineage, harvest rites, and cosmology.', 'history' => 'Dwelling across the mountainous heartland of Northern Luzon, the Ifugao engineered monumental stone-walled rice terraces while developing complex woodcarving, hudhud chants, and backstrap weaving.', 'cultural_background' => 'Ifugao society honors the Baki ritual system led by Mumbaki priests, linking agricultural cycles, ancestral spirits (Anito), and social rank with specific woven garments and blankets.', 'traditional_practices' => 'Using native cotton spun on wooden spindles and dyed in fermented indigo and river mud, weavers work on backstrap and pedal looms to embed lightning, python, and human-ancestor motifs.', 'cultural_significance' => 'Gammit and Baya\'ong textiles demarcate social responsibility, accompany rites of passage from birth to funerary shrouding, and symbolize kinship solidarity across mountain villages.', 'image' => 'Inabel.png'],
    ['tribe_id' => 3, 'tribe_name' => 'Yakan', 'location' => 'Lamitan & Interior Basilan Island (Mindanao & Sulu)', 'description' => 'Master geometric tapestry weavers of the Sulu Archipelago celebrated for the dazzling kaleidoscopic symmetry of the Seputangan headcloth.', 'history' => 'Indigenous to the volcanic interior of Basilan Island, the Yakan developed one of Southeast Asia\'s most mathematically complex supplementary-weft and tapestry weaving traditions.', 'cultural_background' => 'Blending ancient pre-Islamic botanical symbolism with Islamic geometric aesthetics, Yakan visual culture celebrates vibrant color harmony, agricultural fertility, and community resilience.', 'traditional_practices' => 'Working on tensioned backstrap looms (Tenun), Yakan weavers spend weeks executing minute diamond, bamboo-shoot, and fish-scale patterns in fine cotton and silk threads.', 'cultural_significance' => 'Worn as ceremonial headcloths (Seputangan), waist sashes, and bridal ensembles, Yakan textiles signify personal honor, marital readiness, and cultural continuity.', 'image' => 'Seputangan.png'],
    ['tribe_id' => 4, 'tribe_name' => 'Maranao', 'location' => 'Marawi & Lake Lanao Basin, Lanao del Sur (Mindanao & Sulu)', 'description' => 'The \'People of the Lake,\' creators of regal Inaul malong textiles and intricately woven Langkit decorative strips adorned with Okir motifs.', 'history' => 'Centered around Lake Lanao—the second-largest lake in the Philippines—the Maranao established the historic Four Principalities of Lanao (Pat a Pangampong ko Ranao) and a sophisticated royal court aesthetic.', 'cultural_background' => 'Maranao artistry is governed by Okir, a fluid system of curvilinear scrollwork, fern fronds (pako rabong), and mythical serpent (naga) motifs applied to Torogan royal houses, brassware, and textiles.', 'traditional_practices' => 'Weavers operate wide frame looms for tubular Inaul garments and narrow backstrap looms for Langkit tapestry strips, interweaving gold, maroon, purple, and emerald threads.', 'cultural_significance' => 'The Malong Landap and Inaul fabrics represent royal authority, modesty, and lifecycle milestones—cradling newborns, gracing enthronements, and honoring elders.', 'image' => 'Maranao.png'],
];

$initialWeaves = [
    ['weave_id' => 1, 'id' => 'ATWKP-01', 'tribe' => 'Mandaya', 'name' => 'Dagmay Sacred Abaca Ikat', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Critically Endangered', 'loc' => 'Davao Oriental / Caraga', 'fiber' => 'Mud-dyed abaca fiber & Klubi root', 'dyes' => 'Klubi root & Tagum indigo', 'loom' => 'Backstrap Loom', 'culture' => 'Dream-linked sacred abaca textile woven by Mandaya women for ceremonial blankets.', 'motifs' => 'Crocodile (Tambuko), human ancestral figures, and interlocking diamond bands.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-01)', 'fpic' => 'Cleared by Mandaya Council of Elders', 'image' => 'mandaya.jpg', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 2, 'id' => 'ATWKP-02', 'tribe' => 'T\'boli', 'name' => 'T\'nalak Abaca Ikat', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Lake Sebu, South Cotabato', 'fiber' => 'Hand-scraped fine abaca', 'dyes' => 'Klubi bark and river mud', 'loom' => 'Backstrap Loom', 'culture' => 'Sacred cloth revealed by Fu Dalu in dreams to Lake Sebu dream-weavers.', 'motifs' => 'Bangala (frog), Kleng (crab), Sawo (python skin), and Bed T\'nalak clouds.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-02)', 'fpic' => 'Cleared by T\'boli Cultural Foundation', 'image' => 'Tnalak.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 3, 'id' => 'ATWKP-03', 'tribe' => 'Hanunó\'o Mangyan', 'name' => 'Ramit Indigo Cloth', 'region' => 'Visayas & Mindoro', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Oriental Mindoro', 'fiber' => 'Native Cotton', 'dyes' => 'Natural Indigo', 'loom' => 'Mangyan Backstrap Loom', 'culture' => 'Indigo skirt cloth featuring the Pakudos geometric motif to ward off spirits.', 'motifs' => 'Pakudos cross-symmetrical motif embroidered in contrasting white and red cotton.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-03)', 'fpic' => 'Cleared by Mangyan Heritage Center', 'image' => 'Ramit.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 4, 'id' => 'ATWKP-04', 'tribe' => 'Ifugao', 'name' => 'Inabel & Gammit Weave', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Banaue, Ifugao', 'fiber' => 'Handspun Cotton', 'dyes' => 'Mud-dye & Indigo', 'loom' => 'Pedal & Backstrap Looms', 'culture' => 'Traditional ritual blankets and garments woven during harvest and mortuary rites.', 'motifs' => 'Tinaggu (human ancestor), Ginaspala (centipede), and Kimi (lightning) stripes.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-04)', 'fpic' => 'Cleared by Ifugao Traditional Elders', 'image' => 'Inabel.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 5, 'id' => 'ATWKP-05', 'tribe' => 'Subanen', 'name' => 'Gagung & Inagol Textile', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Critically Endangered', 'loc' => 'Zamboanga Peninsula', 'fiber' => 'Wild Abaca & Upland Cotton', 'dyes' => 'Kiput roots & Tagum', 'loom' => 'Backstrap Tension Loom', 'culture' => 'Woven by Balian during Buklog thanksgiving rituals as protective spiritual boundaries.', 'motifs' => 'Altar-platform geometry and protective border stripes used in Buklog rites.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-05)', 'fpic' => 'Cleared by Subanen Council of Elders', 'image' => 'Gagung.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 6, 'id' => 'ATWKP-06', 'tribe' => 'Yakan', 'name' => 'Seputangan Tapestry', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Basilan', 'fiber' => 'Cotton & Silk', 'dyes' => 'Botanical Dyes', 'loom' => 'Backstrap Tapestry Loom', 'culture' => 'High-density tapestry weave used as headcloth with strict geometric symmetry.', 'motifs' => 'Bunga Sama (python), Kabban Buddi (diamond grid), and Dawen-dawen leaves.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-06)', 'fpic' => 'Cleared by Yakan Weavers Guild', 'image' => 'Seputangan.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 7, 'id' => 'ATWKP-07', 'tribe' => 'Suludnon / Panay Bukidnon', 'name' => 'Panubok & Tultul Weave', 'region' => 'Visayas & Mindoro', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Central Panay Ranges', 'fiber' => 'Native Hand-spun Cotton', 'dyes' => 'Sibukao heartwood & Turmeric', 'loom' => 'Traditional Backstrap Loom', 'culture' => 'Embellished with Panubok embroidery symbolizing forest and river spirits.', 'motifs' => 'Binukaw (citrus flower), Linantay, and Matangpunay (pigeon eye) embroidery.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-07)', 'fpic' => 'Cleared by Panay Bukidnon Council', 'image' => 'Panubok.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 8, 'id' => 'ATWKP-08', 'tribe' => 'Ibaloi', 'name' => 'Kuchel & Kuba Shrouds', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Endangered', 'loc' => 'Kabayan, Benguet', 'fiber' => 'Upland Cotton', 'dyes' => 'Achuete & Bark Tannin', 'loom' => 'Ibaloi Backstrap Loom', 'culture' => 'Sacred burial textiles used in mummification and high-status death rituals in Benguet caves.', 'motifs' => 'Stepped mountain crests, red-and-black warp bands, and protective shield blocks.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-08)', 'fpic' => 'Cleared by Kabayan Elders Council', 'image' => 'Kuba.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 9, 'id' => 'ATWKP-09', 'tribe' => 'Bugkalot / Ilongot', 'name' => 'Kawat & Beaded Belts', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Sierra Madre / Quirino', 'fiber' => 'Wild Abaca & Seed Beads', 'dyes' => 'Forest root extracts', 'loom' => 'Tension Frame Loom', 'culture' => 'Worn during headhunting transition rituals and marriage ceremonies across Sierra Madre.', 'motifs' => 'Hornbill beak triangles, cowrie-shell borders, and riverine zigzags.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-09)', 'fpic' => 'Cleared by Bugkalot Confederation', 'image' => 'Kawat.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 10, 'id' => 'ATWKP-10', 'tribe' => 'Teduray / Tiruray', 'name' => 'Akfè & Tebung Weaves', 'region' => 'Mindanao & Sulu', 'medium' => 'Bark Cloth', 'vuln' => 'Critically Endangered', 'loc' => 'Maguindanao del Sur Upland', 'fiber' => 'Artocarpus Inner Bark', 'dyes' => 'Natural charcoal & clay', 'loom' => 'Hammered Bark Frame', 'culture' => 'Ancient bark cloth garments utilized prior to widespread cotton introduction.', 'motifs' => 'Textured stone-mallet striations and natural mineral earth-ochre washes.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-10)', 'fpic' => 'Cleared by Teduray Justice Council', 'image' => 'Akfè.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 11, 'id' => 'ATWKP-11', 'tribe' => 'Agta / Dumagat', 'name' => 'Tinukpuk Bark Cloth', 'region' => 'Luzon', 'medium' => 'Bark Cloth', 'vuln' => 'Critically Endangered', 'loc' => 'Sierra Madre / Isabela', 'fiber' => 'Ficus species inner bark', 'dyes' => 'Raw tree sap & tannins', 'loom' => 'Mallet Beating Stones', 'culture' => 'Nomadic forest attire created by harvesting and hammering wild tree bark until pliable.', 'motifs' => 'Interlaced bast fiber grain accentuated with crimson tree-sap linear markings.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-11)', 'fpic' => 'Cleared by Agta Tribal Association', 'image' => 'Tinuk.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 12, 'id' => 'ATWKP-12', 'tribe' => 'Banwaon', 'name' => 'Inagol Abaca Cloth', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Endangered', 'loc' => 'Agusan del Sur Interior', 'fiber' => 'Wild Upland Abaca', 'dyes' => 'Native roots & river silt', 'loom' => 'Backstrap Loom', 'culture' => 'Woven by upland Banwaon matriarchs for tribal peace pacts and Dugso rituals.', 'motifs' => 'Alternating earth-brown and russet warp stripes representing forest trails.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-12)', 'fpic' => 'Cleared by Banwaon Elders', 'image' => 'Abaca.jpg', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 13, 'id' => 'ATWKP-13', 'tribe' => 'Tagbanwa & Palaw\'an', 'name' => 'Tingkop Spirit Basketry', 'region' => 'Visayas & Mindoro', 'medium' => 'Plaited Fiber', 'vuln' => 'Vulnerable', 'loc' => 'Central & Southern Palawan', 'fiber' => 'Black & Natural Buri', 'dyes' => 'Smoked black bamboo', 'loom' => 'Hand-plaiting Frame', 'culture' => 'Sacred storage and ritual rice baskets featuring contrasting black and natural weave motifs.', 'motifs' => 'Punsik, Kubang, and bird-flight diagonal twill plaiting used in Pagdiwata.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-13)', 'fpic' => 'Cleared by Palawan Tribal Council', 'image' => 'Tingkop.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 14, 'id' => 'ATWKP-14', 'tribe' => 'Erumanen Manobo', 'name' => 'Inigpas & Kamagi', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Endangered', 'loc' => 'Cotabato / Bukidnon Border', 'fiber' => 'Wild Upland Abaca', 'dyes' => 'Boiled mangrove extract', 'loom' => 'Backstrap Loom', 'culture' => 'Sacred ceremonial cloth worn during traditional council assemblies and healing rites.', 'motifs' => 'Tribal datu chevron bands and ancestral shield lattice patterns.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-14)', 'fpic' => 'Cleared by Manobo Governance', 'image' => 'Inigpass.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 15, 'id' => 'ATWKP-15', 'tribe' => 'Magahat / Karol-an', 'name' => 'Gabe Leaf Weave', 'region' => 'Visayas & Mindoro', 'medium' => 'Plaited Fiber', 'vuln' => 'Critically Endangered', 'loc' => 'Negros Occidental Interior', 'fiber' => 'Stripped Forest Palm', 'dyes' => 'Natural un-dyed tones', 'loom' => 'Hand Plaiting Matrix', 'culture' => 'Coarse utility basketry woven by interior forest communities in Negros.', 'motifs' => 'Hexagonal open-weave and over-two twill plaiting for upland harvest transport.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-15)', 'fpic' => 'Cleared by Karol-an Elders', 'image' => 'gabess.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 16, 'id' => 'ATWKP-16', 'tribe' => 'Balangao', 'name' => 'Ingag-ing Textile', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Natonin, Mountain Province', 'fiber' => 'Hand-spun Cotton', 'dyes' => 'Red sibukao & charcoal', 'loom' => 'Cordillera Backstrap Loom', 'culture' => 'Traditional warp-striped blankets and garments utilized for seasonal mountain festivals.', 'motifs' => 'Crimson-and-charcoal warp bands with supplementary float-weave stars.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-16)', 'fpic' => 'Cleared by Balangao Elders', 'image' => 'ingag.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 17, 'id' => 'ATWKP-17', 'tribe' => 'B\'laan', 'name' => 'Tabih Abaca Ikat', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Endangered', 'loc' => 'Sarangani & South Cotabato', 'fiber' => 'Wild Abaca', 'dyes' => 'Natural earth mud', 'loom' => 'Backstrap Ikat Loom', 'culture' => 'Sacred prestige textile woven by master artisans, holding ancestral spiritual energies.', 'motifs' => 'Buaya (crocodile) medallions and precision reserve-dyed crimson panels.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-17)', 'fpic' => 'Cleared by B\'laan Tribal Council', 'image' => 'Tabih.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 18, 'id' => 'ATWKP-18', 'tribe' => 'Bagobo Tagabawa', 'name' => 'Inabal Ceremonial Weave', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Mt. Apo Foothills', 'fiber' => 'Fine Abaca Fiber', 'dyes' => 'Klubi & turmeric mixture', 'loom' => 'Backstrap Loom', 'culture' => 'Traditional warrior and chief attire featuring complex warp-ikat motifs.', 'motifs' => 'Polished beeswax lustre, dancing ancestral figures, and Mt. Apo stars.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-18)', 'fpic' => 'Cleared by Bagobo Tagabawa Elders', 'image' => 'inabal.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 19, 'id' => 'ATWKP-19', 'tribe' => 'Tausūg', 'name' => 'Pis Syabit Headcloth', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Jolo, Sulu Archipelago', 'fiber' => 'Cotton & Silk Blend', 'dyes' => 'Vibrant fast dyes', 'loom' => 'Tapestry Hand Loom', 'culture' => 'Complex double-sided geometric headcloth worn by Tausūg royalty and leaders.', 'motifs' => 'Eight-fold geometric mandala grid with interlocking diagonal polychrome squares.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-19)', 'fpic' => 'Cleared by Sulu Cultural Office', 'image' => 'Pis.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 20, 'id' => 'ATWKP-20', 'tribe' => 'Kalinga', 'name' => 'Kain & Baya-o Weave', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Mabilong, Lubuagan', 'fiber' => 'Cotton Yarn', 'dyes' => 'Vibrant Red & Yellow', 'loom' => 'Backstrap Frame Loom', 'culture' => 'Bold wrap-around skirt cloth featuring brilliant red stripes and mother-of-pearl.', 'motifs' => 'Silambitwon diamond bands symbolizing skyworld, mountain terraces, and river.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-20)', 'fpic' => 'Cleared by Kalinga Council', 'image' => 'Baya-o.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 21, 'id' => 'ATWKP-21', 'tribe' => 'Bontoc', 'name' => 'Lufid & Khata Skirt', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Samoki, Mountain Province', 'fiber' => 'Handwoven Cotton', 'dyes' => 'Natural Indigo & Red Madder', 'loom' => 'Backstrap Loom', 'culture' => 'Traditional wrap-around skirts woven with geometric bandings for agricultural abundance.', 'motifs' => 'Tiko-tiko (mountain path), Kulibangbang (butterfly), and central Fatek panel.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-21)', 'fpic' => 'Cleared by Bontoc Elders', 'image' => 'Lufid.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 22, 'id' => 'ATWKP-22', 'tribe' => 'Gaddang', 'name' => 'Bakwat Beaded Belt', 'region' => 'Luzon', 'medium' => 'Textile', 'vuln' => 'Vulnerable', 'loc' => 'Bayombong, Nueva Vizcaya', 'fiber' => 'Cotton Thread & Beads', 'dyes' => 'Plant Extracts', 'loom' => 'Backstrap Beading Loom', 'culture' => 'Intricate postpartum woven belt adorned with beadwork and geometric protective bands.', 'motifs' => 'Dense white glass seed-bead borders framing crimson-and-yellow woven cotton.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-22)', 'fpic' => 'Cleared by Gaddang Council', 'image' => 'Bakwat.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 23, 'id' => 'ATWKP-23', 'tribe' => 'Samal / Badjao', 'name' => 'Tepoh Pandan Mat', 'region' => 'Mindanao & Sulu', 'medium' => 'Plaited Fiber', 'vuln' => 'Sustained', 'loc' => 'Tawi-Tawi & Sulu', 'fiber' => 'Pandan Leaves', 'dyes' => 'Aniline Dyes', 'loom' => 'Hand-plaiting Mat Frame', 'culture' => 'Finely plaited sleeping and ceremonial mats featuring striking geometric color blocks.', 'motifs' => 'Sekko (zigzag waves), Kabang-kabang (squares), and marine coral geometry.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-23)', 'fpic' => 'Cleared by Samal Elders', 'image' => 'Tepoh.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
    ['weave_id' => 24, 'id' => 'ATWKP-24', 'tribe' => 'Maranao', 'name' => 'Inaul & Langkit Tapestry', 'region' => 'Mindanao & Sulu', 'medium' => 'Textile', 'vuln' => 'Sustained', 'loc' => 'Marawi, Lanao del Sur', 'fiber' => 'Rayon & Cotton Silk', 'dyes' => 'Gold, Black, Maroon Dyes', 'loom' => 'Frame Loom & Langkit', 'culture' => 'Regal hand-woven fabric and decorative panels embodying Maranao royalty and art.', 'motifs' => 'Okir scrollwork, Pako Rabong (growing fern), and Binitoon (star) tapestry bands.', 'docSource' => 'NCIP Ethnographic Field Archive (ATWKP-24)', 'fpic' => 'Cleared by Lanao Sultanate', 'image' => 'Maranao.png', 'status' => 'APPROVED', 'submitted_by' => 1, 'admin_feedback' => '', 'created_at' => '2026-01-15'],
];

$initialUsers = [
    ['user_id' => 1, 'full_name' => 'NCIP Archive Curator', 'username' => 'admin', 'email' => 'admin@tribalweave.ph', 'password' => 'Admin@123', 'role' => 'ADMIN', 'created_at' => 'January 10, 2026'],
    ['user_id' => 2, 'full_name' => 'Maria Dulay (Field Researcher)', 'username' => 'member', 'email' => 'member@tribalweave.ph', 'password' => 'Member@123', 'role' => 'MEMBER', 'created_at' => 'February 14, 2026'],
];


if (!$isCli && isset($_GET['reset'])) {
    say('--reset is only available from the command line (php setup.php --reset). Nothing was changed.');
}
if ($isCli && in_array('--reset', $_SERVER['argv'] ?? [], true)) {
    foreach ([DB_PATH, DB_PATH . '-journal', DB_PATH . '-wal', DB_PATH . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    say('Database file removed (--reset).');
}

foreach ([dirname(DB_PATH), UPLOADS_PATH, STORAGE_PATH, STORAGE_PATH . '/sessions'] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

try {
    $pdo = db();
    $pdo->exec((string) file_get_contents(SCHEMA_PATH));   // CREATE TABLE IF NOT EXISTS ...
    $pdo->beginTransaction();

    // Lookup tables
    $st = $pdo->prepare('INSERT OR IGNORE INTO regions (region_name) VALUES (:n)');
    foreach (['Luzon', 'Visayas & Mindoro', 'Mindanao & Sulu'] as $n) {
        $st->execute(['n' => $n]);
    }
    $st = $pdo->prepare('INSERT OR IGNORE INTO mediums (medium_name) VALUES (:n)');
    foreach (['Textile', 'Bark Cloth', 'Plaited Fiber'] as $n) {
        $st->execute(['n' => $n]);
    }
    $st = $pdo->prepare('INSERT OR IGNORE INTO vulnerability_levels (level_name, sort_order) VALUES (:n, :o)');
    foreach (['Sustained' => 1, 'Vulnerable' => 2, 'Endangered' => 3, 'Critically Endangered' => 4] as $n => $o) {
        $st->execute(['n' => $n, 'o' => $o]);
    }

    $count = fn (string $table): int => (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();

    // Users (passwords are stored hashed, never as plain text)
    if ($count('users') === 0) {
        $st = $pdo->prepare('INSERT INTO users (user_id, full_name, username, email, password_hash, role, created_at)
                             VALUES (:id, :f, :u, :e, :h, :r, :c)');
        foreach ($initialUsers as $u) {
            $st->execute([
                'id' => $u['user_id'], 'f' => $u['full_name'], 'u' => $u['username'], 'e' => $u['email'],
                'h' => password_hash($u['password'], PASSWORD_DEFAULT), 'r' => $u['role'], 'c' => $u['created_at'],
            ]);
        }
        say('Seeded ' . count($initialUsers) . ' users (hashed passwords).');
    }

    // Tribes: 4 featured with full data + one stub row per remaining tribe name used by the weaves
    if ($count('tribes') === 0) {
        $st = $pdo->prepare('INSERT INTO tribes (tribe_id, tribe_name, location, description, history, cultural_background,
                                traditional_practices, cultural_significance, image, is_featured)
                             VALUES (:id, :n, :l, :d, :h, :cb, :tp, :cs, :i, 1)');
        foreach ($initialTribes as $t) {
            $st->execute([
                'id' => $t['tribe_id'], 'n' => $t['tribe_name'], 'l' => $t['location'], 'd' => $t['description'],
                'h' => $t['history'], 'cb' => $t['cultural_background'], 'tp' => $t['traditional_practices'],
                'cs' => $t['cultural_significance'], 'i' => $t['image'],
            ]);
        }
        $known = [];
        foreach ($pdo->query('SELECT tribe_id, tribe_name FROM tribes')->fetchAll() as $r) {
            $known[mb_strtolower($r['tribe_name'])] = (int) $r['tribe_id'];
        }
        $stub = $pdo->prepare('INSERT INTO tribes (tribe_name, location, image, is_featured) VALUES (:n, :l, :i, 0)');
        foreach ($initialWeaves as $w) {
            if (!isset($known[mb_strtolower($w['tribe'])])) {
                $stub->execute(['n' => $w['tribe'], 'l' => $w['loc'], 'i' => $w['image']]);
                $known[mb_strtolower($w['tribe'])] = (int) $pdo->lastInsertId();
            }
        }
        say('Seeded ' . $count('tribes') . ' tribes (' . count($initialTribes) . ' featured).');
    }

    // Weaves
    if ($count('weaves') === 0) {
        $tribeIds = [];
        foreach ($pdo->query('SELECT tribe_id, tribe_name FROM tribes')->fetchAll() as $r) {
            $tribeIds[mb_strtolower($r['tribe_name'])] = (int) $r['tribe_id'];
        }
        $lookup = function (string $sql, string $name) use ($pdo): int {
            $st = $pdo->prepare($sql);
            $st->execute(['n' => $name]);
            $id = $st->fetchColumn();
            if ($id === false) {
                throw new RuntimeException('Unknown lookup value: ' . $name);
            }
            return (int) $id;
        };
        $st = $pdo->prepare('INSERT INTO weaves (weave_id, catalog_code, tribe_id, region_id, medium_id, level_id, name, location,
                                fiber, dyes, loom, cultural_significance, motifs, doc_source, fpic, image, status, admin_feedback,
                                submitted_by, created_at, updated_at)
                             VALUES (:id, :code, :tribe, :region, :medium, :level, :name, :loc, :fiber, :dyes, :loom, :culture,
                                :motifs, :doc, :fpic, :image, :status, :fb, :by, :created, :created2)');
        foreach ($initialWeaves as $w) {
            $st->execute([
                'id' => $w['weave_id'], 'code' => $w['id'], 'tribe' => $tribeIds[mb_strtolower($w['tribe'])],
                'region' => $lookup('SELECT region_id FROM regions WHERE region_name = :n', $w['region']),
                'medium' => $lookup('SELECT medium_id FROM mediums WHERE medium_name = :n', $w['medium']),
                'level'  => $lookup('SELECT level_id FROM vulnerability_levels WHERE level_name = :n', $w['vuln']),
                'name' => $w['name'], 'loc' => $w['loc'], 'fiber' => $w['fiber'], 'dyes' => $w['dyes'], 'loom' => $w['loom'],
                'culture' => $w['culture'], 'motifs' => $w['motifs'], 'doc' => $w['docSource'], 'fpic' => $w['fpic'],
                'image' => $w['image'], 'status' => $w['status'], 'fb' => $w['admin_feedback'], 'by' => $w['submitted_by'],
                'created' => $w['created_at'], 'created2' => $w['created_at'],
            ]);
        }
        say('Seeded ' . count($initialWeaves) . ' weaves.');
    }

    $pdo->commit();
    say('Setup complete. Database: ' . DB_PATH);
    say('Demo accounts are listed in README.md (change them before any real deployment).');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[TWA setup] ' . $e->getMessage());
    http_response_code(500);
    say('Setup failed: ' . $e->getMessage());
    exit(1);
}
