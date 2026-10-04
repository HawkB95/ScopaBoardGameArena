CREATE TABLE IF NOT EXISTS `scopa_card` (
  `card_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_type` VARCHAR(20) NOT NULL,
  `card_type_arg` INT(11) NOT NULL,
  `card_location` VARCHAR(20) NOT NULL,
  `card_location_arg` INT(11) NOT NULL,
  PRIMARY KEY (`card_id`),
  KEY `card_location` (`card_location`, `card_location_arg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;
