CREATE TABLE `DynUpdate` (
  `Id` int(11) NOT NULL,
  `Settlement` int(11) NOT NULL,
  `DateTime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `IP` varchar(32)  NOT NULL,
  `Updated` tinyint(1) NOT NULL
)

CREATE TABLE `Settlement` (
  `Id` int(11) NOT NULL,
  `Name` varchar(100) NOT NULL,
  `SubDomain` varchar(100) DEFAULT NULL,
  `Token` varchar(100) DEFAULT NULL
)

INSERT INTO `Settlement` (`Id`, `Name`, `SubDomain`, `Token`) VALUES (1, 'Sample', 'sub', 'usertoken');

ALTER TABLE `DynUpdate`
  ADD PRIMARY KEY (`Id`),
  ADD KEY `SettlementDateTime` (`Settlement`,`DateTime`);

ALTER TABLE `Settlement`
  ADD PRIMARY KEY (`Id`),
  ADD UNIQUE KEY `SubDomain` (`SubDomain`),
  ADD UNIQUE KEY `Token` (`Token`),
  ADD KEY `Name` (`Name`);

ALTER TABLE `DynUpdate`
  MODIFY `Id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `Settlement`
  MODIFY `Id` int(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `DynUpdate`
  ADD CONSTRAINT `FK_DynUpdate_Settlement` FOREIGN KEY (`Settlement`) REFERENCES `Settlement` (`Id`) ON DELETE CASCADE;
COMMIT;
