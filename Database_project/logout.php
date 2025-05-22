<?php
	session_start();

	unset($_SESSION['UserID']);
	unset($_SESSION['Fname']);

	echo "<script> window.location='index.php'; </script>";