
<h1>{L_PROFILE_FIELD_LIST_TITLE}</h1>

<P>{L_PROFILE_FIELD_LIST_EXPLAIN}</p>

<table cellspacing="1" cellpadding="4" border="0" align="center" class="forumline">
	<tr>
		<th class="thCornerL">{L_ID}</th>
		<th class="thTop">{L_NAME}</th>
		<th colspan="2" class="thCornerR">{L_ACTION}</th>
	</tr>
	<!-- BEGIN switch_no_fields -->
	<tr>
	  <td class="row1" colspan="4">{switch_no_fields.NO_FIELDS_EXIST}</td>
	</tr>
	<!-- END switch_no_fields -->
	<!-- BEGIN switch_fields -->
	<!-- BEGIN profile_fields -->
	<tr>
		<td class="{switch_fields.profile_fields.ROW_CLASS}" align="center">{switch_fields.profile_fields.ID}</td>
		<td class="{switch_fields.profile_fields.ROW_CLASS}">{switch_fields.profile_fields.NAME}</td>
		<td class="{switch_fields.profile_fields.ROW_CLASS}"><a href="{switch_fields.profile_fields.U_PROFILE_FIELD_EDIT}">{L_EDIT}</a></td>
		<td class="{switch_fields.profile_fields.ROW_CLASS}"><a href="{switch_fields.profile_fields.U_PROFILE_FIELD_DELETE}">{L_DELETE}</a></td>
	</tr>
	<!-- END profile_fields -->
	<!-- END switch_fields -->
	<tr>
		<td class="catBottom" colspan="4" align="center">{S_HIDDEN_FIELDS}</td>
	</tr>
</table>
<h2>{L_RETIRED_TITLE}</h2>
<p>{L_RETIRED_EXPLAIN}</p>
<table cellspacing="1" cellpadding="4" border="0" align="center" class="forumline">
  <tr><th>{L_ID}</th><th>{L_NAME}</th><th>{L_ACTION}</th></tr>
  <!-- BEGIN retired_fields -->
  <tr>
    <td class="row1">{retired_fields.ID}</td>
    <td class="row1">{retired_fields.NAME}</td>
    <td class="row1"><a href="{retired_fields.U_RESTORE}">{L_RESTORE}</a></td>
  </tr>
  <!-- END retired_fields -->
</table>
