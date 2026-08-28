<table width="100%" cellspacing="2" cellpadding="2" border="0" align="center">
   <tr>
      <td align="left" class="nav"><a href="{U_INDEX}" class="nav">{L_INDEX}</a></td>
   </tr>
</table>
<p align="center"><span class="cattitle">{TITLE}</center></span>
<table class="forumline" width="100%" cellspacing="1" cellpadding="3" border="0" align="center">
   <tr>
      <th class="thCornerL" width="50">Rank</th>
      <th class="thTop" width="100">Name</th>
      <th class="thTop"" width="200">Current Monthly HiScores</th>
      <th class="thTop" width="200">Total All Time HiScores</th>
      <th class="thCornerR" width="200">Total Points</th>
   </tr>

<!-- BEGIN total -->
   <tr align="center">
      <td class="row2"><span class="gen">{total.RANK}</span></td>
      <td class="row1"><span class="gen">{total.NAME}</span></td>
      <td class="row2"><span class="gen">{total.MONTH_TOTAL}</span></td>
      <td class="row2"><span class="gen">{total.AT_TOTAL}</span></td>
      <td class="row3"><span class="gen"><b>{total.TOTAL}</span></b></td>
   </tr>
<!-- END total -->

   <tr>
      <td class="catBottom" height="28">&nbsp;</td>
      <td class="catBottom" height="28">&nbsp;</td>
      <td class="catBottom" height="28">&nbsp;</td>
      <td class="catBottom" height="28">&nbsp;</td>
      <td class="catBottom" height="28">&nbsp;</td>
   </tr>
</table>
<span class="gen">
<b>Design Layout:</b><br />
This list adds all Current HiScores and All Time HiScores per player.<br />
Calculation of total points:<br />
      * Current HiScores = {C_MONTH} Point(s)<br />
      * All Time HiScores = {C_ALL_TIME} Points<br /><br />
** Every 3 months, the High Scores are reset and the list changes **<br /><br />
<b>List Stats:</b><br />
{INFOTEXT}</br>
</br>
</span>
