<?php
if(isset($message)){
   foreach($message as $message){
      echo '
      <div class="message">
         <span>'.$message.'</span>
         <i class="fas fa-times" onclick="this.parentElement.remove();"></i>
      </div>
      ';
   }
}
?>
<style></style>
<header class="header">

     <div class="header-2">
      <div class="flex">
         <a href="home.php" class="logo">Trishe</a>

         <nav class="navbar">
         
            <a href="home.php" class="navbar-link hover-1" data-nav-toggler>home</a>
            
            <a href="about.php" class="navbar-link hover-1" data-nav-toggler>about</a>
            <a href="shop.php" class="navbar-link hover-1" data-nav-toggler>shop</a>
            <a href="contact.php" class="navbar-link hover-1" data-nav-toggler>contact</a>
            <a href="orders.php" class="navbar-link hover-1" data-nav-toggler>orders</a>
         </nav>
         
         <div class="icons">
            <div id="menu-btn" class="fas fa-bars"></div>
            <a href="search_page.php" class="fas fa-search"></a>
            <div id="user-btn" class="fas fa-user"></div>
            <?php
               $select_cart_number = mysqli_query($conn, "SELECT * FROM `cart` WHERE user_id = '$user_id'") or die('query failed');
               $cart_rows_number = mysqli_num_rows($select_cart_number); 
            ?>
            <a href="cart.php"> <i class="fas fa-shopping-cart"></i> <span>(<?php echo $cart_rows_number; ?>)</span>  hellow</a>
         </div>

         <div class="user-box">
            <p>username : <span><?php echo $_SESSION['user_name']; ?></span></p>
            <p>email : <span><?php echo $_SESSION['user_email']; ?></span></p>
            <a href="logout.php" class="delete-btn">logout</a>
         </div>
      </div>
   </div>

</header>