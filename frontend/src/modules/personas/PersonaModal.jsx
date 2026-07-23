import React, { useEffect, useMemo, useRef, useState } from "react";
import {
  buildPersonSections,
  catalogToOptions,
  GLOBAL_OPTIONS,
} from "../../config/globalOptions";
import CrudModal from "../../Global/components/CrudModal";
import {
  EntityFormPanel,
  EntityTabs,
} from "../../Global/components/TabbedForm";
import GlobalIcon from "../../Global/components/GlobalIcon";
import SearchableSelect from "../../Global/components/SearchableSelect";

const localDateValue = (date = new Date()) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};
const isTrue = (value) => value === true || value === 1 || value === "1";
const asValue = (value) =>
  value === null || value === undefined ? "" : String(value);
const byCode = (items, code) =>
  items.find((item) => item.codigo === code || item.codigo_iso2 === code);
const UI_ACRONYMS = /\b(dni|cuit|cuil|iva|pep|arca|inaes|cbu)\b/gi;

const toUiLabel = (value) => {
  const text = String(value ?? "").trim();
  if (!text) return text;
  const normalized = `${text.charAt(0).toLocaleUpperCase("es-AR")}${text.slice(1).toLocaleLowerCase("es-AR")}`;
  return normalized.replace(UI_ACRONYMS, (match) =>
    match.toLocaleUpperCase("es-AR"),
  );
};


const DIGIT_FIELD_LIMITS = {
  "general.cuit_cuil": 11,
  "general.telefono": 20,
  "general.telefono_alternativo": 20,
  "fisica.dni": 8,
  "fisica.telefono_laboral": 20,
  "financieros.cbu": 22,
  "general.ingresos_brutos": 20,
};

const DATE_FIELDS = new Set([
  "general.fecha_actualizacion_arca",
  "fisica.fecha_nacimiento",
  "conyuge.fecha_desde",
  "juridica.fecha_constitucion",
  "asociado.fecha_ingreso",
  "asociado.fecha_alta_inaes",
  "asociado.fecha_baja",
]);

const NAME_FIELDS = new Set(["fisica.nombres", "fisica.apellidos"]);
const DECIMAL_FIELDS = new Set([
  "financieros.ingresos_mensuales",
  "financieros.patrimonio_estimado",
]);

const STRUCTURED_TEXT_FIELDS = new Set([
  "general.localidad_exterior",
  "general.actividad",
  "fisica.profesion",
  "fisica.empleador",
  "fisica.lugar_trabajo",
  "juridica.razon_social",
  "juridica.nombre_fantasia",
  "juridica.numero_inscripcion",
  "juridica.autoridad_contralor",
  "financieros.banco",
]);

const FREE_TEXT_FIELDS = new Set([
  "general.observaciones",
  "financieros.origen_fondos",
  "financieros.perfil_transaccional",
  "asociado.motivo_baja",
  "conyuge.observaciones",
]);

const FIELD_LIMITS = {
  "general.email": 180,
  "general.domicilio": 220,
  "general.localidad_exterior": 120,
  "general.ingresos_brutos": 20,
  "general.actividad": 180,
  "general.observaciones": 4000,
  "fisica.nombres": 120,
  "fisica.apellidos": 120,
  "fisica.profesion": 140,
  "fisica.empleador": 180,
  "fisica.lugar_trabajo": 220,
  "conyuge.observaciones": 500,
  "juridica.razon_social": 200,
  "juridica.nombre_fantasia": 180,
  "juridica.numero_inscripcion": 100,
  "juridica.autoridad_contralor": 160,
  "juridica.fecha_cierre_ejercicio": 5,
  "financieros.origen_fondos": 500,
  "financieros.perfil_transaccional": 500,
  "financieros.banco": 120,
  "financieros.alias_cbu": 80,
  "asociado.motivo_baja": 255,
};

const upper = (value) => String(value ?? "").toLocaleUpperCase("es-AR");
const limitForPath = (path) => {
  if (FIELD_LIMITS[path]) return FIELD_LIMITS[path];
  const field = path.split(".").at(-1);
  if (field === "observaciones") return 500;
  if (field === "alcance") return 255;
  return null;
};

const sanitizeDecimal = (value) => {
  const cleaned = String(value ?? "")
    .replace(/,/g, ".")
    .replace(/[^0-9.]/g, "");
  const [rawInteger = "", ...decimalParts] = cleaned.split(".");
  const integer = rawInteger.slice(0, 16);
  const decimal = decimalParts.join("").slice(0, 2);
  if (!decimalParts.length) return integer;
  return `${integer || "0"}.${decimal}`;
};

const sanitizeStructuredText = (value) =>
  upper(value).replace(/[^\p{L}\p{M}\p{N}\s.,'&°ºª#():\/+\-]/gu, "");

const sanitizeFreeText = (value) =>
  upper(value).replace(/[^\p{L}\p{M}\p{N}\s.,;:'"¿?¡!°ºª#%&@$()\[\]{}\/+_=\-]/gu, "");

const sanitizeFieldValue = (path, value) => {
  if (typeof value !== "string") return value;

  if (path === "general.email") {
    return value
      .toLocaleLowerCase("es-AR")
      .replace(/\s+/g, "")
      .slice(0, FIELD_LIMITS[path]);
  }

  if (DIGIT_FIELD_LIMITS[path]) {
    return value.replace(/\D+/g, "").slice(0, DIGIT_FIELD_LIMITS[path]);
  }

  if (NAME_FIELDS.has(path)) {
    return upper(value)
      .replace(/[^\p{L}\p{M}\s'-]/gu, "")
      .slice(0, FIELD_LIMITS[path]);
  }

  if (DECIMAL_FIELDS.has(path) || path.endsWith(".porcentaje_participacion")) {
    return sanitizeDecimal(value).slice(0, 18);
  }

  if (path === "general.domicilio") {
    return upper(value)
      .replace(/[^\p{L}\p{M}\p{N}\s.,'°ºª#()\/-]/gu, "")
      .slice(0, FIELD_LIMITS[path]);
  }

  if (path === "juridica.fecha_cierre_ejercicio") {
    const digits = value.replace(/\D+/g, "").slice(0, 4);
    return digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
  }

  if (STRUCTURED_TEXT_FIELDS.has(path)) {
    const normalized = sanitizeStructuredText(value);
    return normalized.slice(0, limitForPath(path) || normalized.length);
  }

  if (
    FREE_TEXT_FIELDS.has(path) ||
    path.endsWith(".observaciones") ||
    path.endsWith(".alcance")
  ) {
    const normalized = sanitizeFreeText(value);
    return normalized.slice(0, limitForPath(path) || normalized.length);
  }

  if (path === "financieros.alias_cbu") {
    return upper(value)
      .replace(/[^\p{L}\p{M}\p{N}._-]/gu, "")
      .slice(0, FIELD_LIMITS[path]);
  }

  if (
    DATE_FIELDS.has(path) ||
    path.endsWith(".fecha_desde") ||
    path.endsWith(".fecha_hasta") ||
    path.endsWith(".id_persona_vinculada") ||
    path.includes(".id_")
  ) {
    return value;
  }

  const normalized = sanitizeFreeText(value);
  const limit = limitForPath(path);
  return limit ? normalized.slice(0, limit) : normalized;
};

const isBlank = (value) => String(value ?? "").trim() === "";
const isFutureDate = (value) => Boolean(value) && value > localDateValue();
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i;
const namePattern = /^[\p{L}\p{M}\s'-]+$/u;
const digitsPattern = /^\d+$/;
const decimalPattern = /^\d{1,16}(?:\.\d{1,2})?$/;
const structuredTextPattern = /^[\p{L}\p{M}\p{N}\s.,'&°ºª#():\/+\-]+$/u;
const freeTextPattern = /^[\p{L}\p{M}\p{N}\s.,;:'"¿?¡!°ºª#%&@$()\[\]{}\/+_=\-]+$/u;
const addressPattern = /^[\p{L}\p{M}\p{N}\s.,'°ºª#()\/-]+$/u;
const aliasPattern = /^[\p{L}\p{M}\p{N}._-]+$/u;

const validatePersonForm = (form) => {
  const errors = {};
  const cuit = String(form.general.cuit_cuil || "");

  if (form.tipo_persona === "JURIDICA" && !cuit) {
    errors["general.cuit_cuil"] = "El CUIT es obligatorio para una persona jurídica.";
  } else if (cuit && cuit.length !== 11) {
    errors["general.cuit_cuil"] = "El CUIT/CUIL debe tener 11 dígitos.";
  }

  if (form.general.email && !emailPattern.test(form.general.email)) {
    errors["general.email"] = "Ingresá un correo electrónico válido.";
  }

  const grossIncome = String(form.general.ingresos_brutos || "");
  if (grossIncome && !digitsPattern.test(grossIncome)) {
    errors["general.ingresos_brutos"] = "Ingresá solamente números.";
  }
  if (form.general.domicilio && !addressPattern.test(form.general.domicilio)) {
    errors["general.domicilio"] = "El domicilio solo admite letras, números y signos habituales.";
  }

  ["telefono", "telefono_alternativo"].forEach((field) => {
    const value = String(form.general[field] || "");
    if (value && (value.length < 6 || value.length > 20)) {
      errors[`general.${field}`] = "Ingresá entre 6 y 20 números.";
    }
  });

  if (isBlank(form.general.id_pais_residencia)) {
    errors["general.id_pais_residencia"] = "Seleccioná el país de residencia.";
  }
  if (isFutureDate(form.general.fecha_actualizacion_arca)) {
    errors["general.fecha_actualizacion_arca"] = "La fecha no puede ser futura.";
  }

  const structuredValues = {
    "general.localidad_exterior": form.general.localidad_exterior,
    "general.actividad": form.general.actividad,
    "fisica.profesion": form.fisica.profesion,
    "fisica.empleador": form.fisica.empleador,
    "fisica.lugar_trabajo": form.fisica.lugar_trabajo,
    "juridica.razon_social": form.juridica.razon_social,
    "juridica.nombre_fantasia": form.juridica.nombre_fantasia,
    "juridica.numero_inscripcion": form.juridica.numero_inscripcion,
    "juridica.autoridad_contralor": form.juridica.autoridad_contralor,
    "financieros.banco": form.financieros.banco,
  };
  Object.entries(structuredValues).forEach(([path, value]) => {
    if (value && !structuredTextPattern.test(String(value))) {
      errors[path] = "El campo contiene caracteres no permitidos.";
    }
  });

  const freeTextValues = {
    "general.observaciones": form.general.observaciones,
    "financieros.origen_fondos": form.financieros.origen_fondos,
    "financieros.perfil_transaccional": form.financieros.perfil_transaccional,
    "asociado.motivo_baja": form.asociado.motivo_baja,
    "conyuge.observaciones": form.conyuge.observaciones,
  };
  Object.entries(freeTextValues).forEach(([path, value]) => {
    if (value && !freeTextPattern.test(String(value))) {
      errors[path] = "El campo contiene caracteres no permitidos.";
    }
  });

  if (form.tipo_persona === "FISICA") {
    const names = String(form.fisica.nombres || "").trim();
    const surnames = String(form.fisica.apellidos || "").trim();
    const dni = String(form.fisica.dni || "");

    if (!names) errors["fisica.nombres"] = "Este campo es obligatorio.";
    else if (!namePattern.test(names)) errors["fisica.nombres"] = "Ingresá solamente letras.";

    if (!surnames) errors["fisica.apellidos"] = "Este campo es obligatorio.";
    else if (!namePattern.test(surnames)) errors["fisica.apellidos"] = "Ingresá solamente letras.";

    if (dni.length < 7 || dni.length > 8) {
      errors["fisica.dni"] = "El DNI debe tener 7 u 8 dígitos.";
    }
    if (isFutureDate(form.fisica.fecha_nacimiento)) {
      errors["fisica.fecha_nacimiento"] = "La fecha de nacimiento no puede ser futura.";
    }
    if (isBlank(form.fisica.id_pais_nacionalidad)) {
      errors["fisica.id_pais_nacionalidad"] = "Seleccioná la nacionalidad.";
    }
    const workPhone = String(form.fisica.telefono_laboral || "");
    if (workPhone && (workPhone.length < 6 || workPhone.length > 20)) {
      errors["fisica.telefono_laboral"] = "Ingresá entre 6 y 20 números.";
    }
    if (isFutureDate(form.conyuge.fecha_desde)) {
      errors["conyuge.fecha_desde"] = "La fecha del vínculo no puede ser futura.";
    }
  } else {
    if (isBlank(form.juridica.razon_social)) {
      errors["juridica.razon_social"] = "Este campo es obligatorio.";
    }
    if (isFutureDate(form.juridica.fecha_constitucion)) {
      errors["juridica.fecha_constitucion"] = "La fecha de constitución no puede ser futura.";
    }
    if (
      form.juridica.fecha_cierre_ejercicio &&
      !/^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])$/.test(
        form.juridica.fecha_cierre_ejercicio,
      )
    ) {
      errors["juridica.fecha_cierre_ejercicio"] = "Usá el formato DD/MM.";
    }
  }

  const cbu = String(form.financieros.cbu || "");
  if (cbu && cbu.length !== 22) {
    errors["financieros.cbu"] = "El CBU debe tener 22 dígitos.";
  }
  ["ingresos_mensuales", "patrimonio_estimado"].forEach((field) => {
    const value = String(form.financieros[field] ?? "");
    if (value !== "" && !decimalPattern.test(value)) {
      errors[`financieros.${field}`] = "Ingresá un importe válido con hasta 2 decimales.";
    }
  });
  if (form.financieros.alias_cbu && !aliasPattern.test(form.financieros.alias_cbu)) {
    errors["financieros.alias_cbu"] = "El alias solo admite letras, números, puntos, guiones y guion bajo.";
  }

  if (form.asociado.es_asociado) {
    if (!form.asociado.fecha_ingreso) {
      errors["asociado.fecha_ingreso"] = "La fecha de ingreso es obligatoria.";
    } else if (isFutureDate(form.asociado.fecha_ingreso)) {
      errors["asociado.fecha_ingreso"] = "La fecha de ingreso no puede ser futura.";
    }
    if (!form.asociado.id_categoria_asociado) {
      errors["asociado.id_categoria_asociado"] = "Seleccioná una categoría.";
    }
    if (!form.asociado.id_sucursal) {
      errors["asociado.id_sucursal"] = "Seleccioná una sucursal.";
    }
    if (isFutureDate(form.asociado.fecha_alta_inaes)) {
      errors["asociado.fecha_alta_inaes"] = "La fecha de alta no puede ser futura.";
    }
    if (form.asociado.estado === "BAJA") {
      if (!form.asociado.fecha_baja) {
        errors["asociado.fecha_baja"] = "Indicá la fecha de baja.";
      } else if (
        form.asociado.fecha_ingreso &&
        form.asociado.fecha_baja < form.asociado.fecha_ingreso
      ) {
        errors["asociado.fecha_baja"] = "La baja no puede ser anterior al ingreso.";
      }
    }
  }

  form.autorizados.forEach((item, index) => {
    if (!item.id_persona_vinculada) {
      errors[`autorizados.${index}.id_persona_vinculada`] = "Seleccioná una persona registrada.";
    }
    if (item.alcance && !freeTextPattern.test(String(item.alcance))) {
      errors[`autorizados.${index}.alcance`] = "El campo contiene caracteres no permitidos.";
    }
    if (item.observaciones && !freeTextPattern.test(String(item.observaciones))) {
      errors[`autorizados.${index}.observaciones`] = "El campo contiene caracteres no permitidos.";
    }
    if (item.fecha_desde && item.fecha_hasta && item.fecha_hasta < item.fecha_desde) {
      errors[`autorizados.${index}.fecha_hasta`] = "La fecha hasta no puede ser anterior a la fecha desde.";
    }
  });

  let participationTotal = 0;
  form.beneficiarios.forEach((item, index) => {
    if (!item.id_persona_vinculada) {
      errors[`beneficiarios.${index}.id_persona_vinculada`] = "Seleccioná una persona física registrada.";
    }
    const percentageText = String(item.porcentaje_participacion ?? "");
    const percentage = Number(percentageText);
    if (
      !decimalPattern.test(percentageText) ||
      !Number.isFinite(percentage) ||
      percentage <= 0 ||
      percentage > 100
    ) {
      errors[`beneficiarios.${index}.porcentaje_participacion`] = "Ingresá un porcentaje mayor a 0 y hasta 100, con hasta 2 decimales.";
    } else {
      participationTotal += percentage;
    }
    if (item.observaciones && !freeTextPattern.test(String(item.observaciones))) {
      errors[`beneficiarios.${index}.observaciones`] = "El campo contiene caracteres no permitidos.";
    }
    if (item.fecha_desde && item.fecha_hasta && item.fecha_hasta < item.fecha_desde) {
      errors[`beneficiarios.${index}.fecha_hasta`] = "La fecha hasta no puede ser anterior a la fecha desde.";
    }
  });
  if (participationTotal > 100.0001) {
    errors.beneficiarios = "La participación total no puede superar el 100%.";
  }

  return errors;
};

const tabForError = (key) => {
  if (key === "tipo_persona" || key.startsWith("general.")) return "general";
  if (key.startsWith("fisica.") || key.startsWith("juridica.") || key.startsWith("conyuge.")) return "specific";
  if (key.startsWith("financieros.")) return "financial";
  if (key.startsWith("asociado.")) return "associate";
  if (key.startsWith("autorizados")) return "authorized";
  if (key.startsWith("beneficiarios")) return "beneficiaries";
  return "general";
};

const createEmptyForm = (catalogs) => ({
  tipo_persona: "FISICA",
  general: {
    cuit_cuil: "",
    email: "",
    telefono: "",
    telefono_alternativo: "",
    domicilio: "",
    id_localidad: "",
    localidad_exterior: "",
    id_pais_residencia: asValue(
      byCode(catalogs.paises, "AR")?.id || catalogs.paises[0]?.id || 1,
    ),
    id_zona_geografica: asValue(
      byCode(catalogs.zonas_geograficas, "INTERIOR_PAIS")?.id || "",
    ),
    id_condicion_iva: asValue(
      byCode(catalogs.condiciones_iva, "CONSUMIDOR_FINAL")?.id || "",
    ),
    ingresos_brutos: "",
    actividad: "",
    residente: true,
    es_pep: false,
    sujeto_obligado: false,
    fecha_actualizacion_arca: "",
    observaciones: "",
  },
  fisica: {
    nombres: "",
    apellidos: "",
    dni: "",
    fecha_nacimiento: "",
    genero: "NO_INFORMA",
    estado_civil: "NO_INFORMA",
    id_pais_nacionalidad: asValue(
      byCode(catalogs.paises, "AR")?.id || catalogs.paises[0]?.id || 1,
    ),
    id_relacion_laboral: "",
    profesion: "",
    empleador: "",
    lugar_trabajo: "",
    telefono_laboral: "",
  },
  conyuge: {
    id_persona_vinculada: "",
    fecha_desde: "",
    observaciones: "",
  },
  juridica: {
    razon_social: "",
    nombre_fantasia: "",
    id_tipo_societario: "",
    fecha_constitucion: "",
    numero_inscripcion: "",
    autoridad_contralor: "",
    fecha_cierre_ejercicio: "",
  },
  financieros: {
    ingresos_mensuales: "",
    patrimonio_estimado: "",
    origen_fondos: "",
    perfil_transaccional: "",
    banco: "",
    cbu: "",
    alias_cbu: "",
  },
  asociado: {
    es_asociado: false,
    fecha_ingreso: localDateValue(),
    id_categoria_asociado: asValue(
      byCode(catalogs.categorias_asociados, "ACTIVO")?.id ||
        catalogs.categorias_asociados[0]?.id ||
        "",
    ),
    id_sucursal: asValue(
      byCode(catalogs.sucursales, "SEDE_CENTRAL")?.id ||
        catalogs.sucursales[0]?.id ||
        "",
    ),
    estado: "ACTIVO",
    cobra_cuota: true,
    debito_automatico: false,
    fecha_alta_inaes: "",
    fecha_baja: "",
    motivo_baja: "",
  },
  autorizados: [],
  beneficiarios: [],
});

const fromDetail = (detail, catalogs) => {
  const form = createEmptyForm(catalogs);
  if (!detail?.persona) return form;

  const person = detail.persona;
  const physical = detail.fisica || {};
  const spouse = detail.conyuge || {};
  const legal = detail.juridica || {};
  const financial = detail.financieros || {};
  const associate = detail.asociado || null;

  return {
    tipo_persona: person.tipo_persona || "FISICA",
    general: {
      cuit_cuil: person.cuit_cuil || "",
      email: String(person.email || "").toLocaleLowerCase("es-AR"),
      telefono: person.telefono || "",
      telefono_alternativo: person.telefono_alternativo || "",
      domicilio: person.domicilio || "",
      id_localidad: asValue(person.id_localidad),
      localidad_exterior: person.localidad_exterior || "",
      id_pais_residencia: asValue(
        person.id_pais_residencia || form.general.id_pais_residencia,
      ),
      id_zona_geografica: asValue(person.id_zona_geografica),
      id_condicion_iva: asValue(person.id_condicion_iva),
      ingresos_brutos: person.ingresos_brutos || "",
      actividad: person.actividad || "",
      residente: isTrue(person.residente),
      es_pep: isTrue(person.es_pep),
      sujeto_obligado: isTrue(person.sujeto_obligado),
      fecha_actualizacion_arca: person.fecha_actualizacion_arca || "",
      observaciones: person.observaciones || "",
    },
    fisica: {
      nombres: physical.nombres || "",
      apellidos: physical.apellidos || "",
      dni: physical.dni || "",
      fecha_nacimiento: physical.fecha_nacimiento || "",
      genero: physical.genero || "NO_INFORMA",
      estado_civil: physical.estado_civil || "NO_INFORMA",
      id_pais_nacionalidad: asValue(
        physical.id_pais_nacionalidad || form.fisica.id_pais_nacionalidad,
      ),
      id_relacion_laboral: asValue(physical.id_relacion_laboral),
      profesion: physical.profesion || "",
      empleador: physical.empleador || "",
      lugar_trabajo: physical.lugar_trabajo || "",
      telefono_laboral: physical.telefono_laboral || "",
    },
    conyuge: {
      id_persona_vinculada: asValue(spouse.id_persona_vinculada),
      fecha_desde: spouse.fecha_desde || "",
      observaciones: spouse.observaciones || "",
    },
    juridica: {
      razon_social: legal.razon_social || "",
      nombre_fantasia: legal.nombre_fantasia || "",
      id_tipo_societario: asValue(legal.id_tipo_societario),
      fecha_constitucion: legal.fecha_constitucion || "",
      numero_inscripcion: legal.numero_inscripcion || "",
      autoridad_contralor: legal.autoridad_contralor || "",
      fecha_cierre_ejercicio: legal.fecha_cierre_ejercicio || "",
    },
    financieros: {
      ingresos_mensuales: asValue(financial.ingresos_mensuales),
      patrimonio_estimado: asValue(financial.patrimonio_estimado),
      origen_fondos: financial.origen_fondos || "",
      perfil_transaccional: financial.perfil_transaccional || "",
      banco: financial.banco || "",
      cbu: financial.cbu || "",
      alias_cbu: financial.alias_cbu || "",
    },
    asociado: {
      es_asociado: Boolean(associate),
      fecha_ingreso: associate?.fecha_ingreso || localDateValue(),
      id_categoria_asociado: asValue(
        associate?.id_categoria_asociado || form.asociado.id_categoria_asociado,
      ),
      id_sucursal: asValue(associate?.id_sucursal || form.asociado.id_sucursal),
      estado: associate?.estado || "ACTIVO",
      cobra_cuota: associate ? isTrue(associate.cobra_cuota) : true,
      debito_automatico: associate
        ? isTrue(associate.debito_automatico)
        : false,
      fecha_alta_inaes: associate?.fecha_alta_inaes || "",
      fecha_baja: associate?.fecha_baja || "",
      motivo_baja: associate?.motivo_baja || "",
    },
    autorizados: (detail.autorizados || []).map((item) => ({
      id_persona_vinculada: asValue(item.id_persona_vinculada),
      alcance: item.alcance || "",
      operaciones_permitidas: Array.isArray(item.operaciones_permitidas)
        ? item.operaciones_permitidas
        : [],
      fecha_desde: item.fecha_desde || "",
      fecha_hasta: item.fecha_hasta || "",
      activo: isTrue(item.activo),
      observaciones: item.observaciones || "",
    })),
    beneficiarios: (detail.beneficiarios || []).map((item) => ({
      id_persona_vinculada: asValue(item.id_persona_vinculada),
      porcentaje_participacion: asValue(item.porcentaje_participacion),
      fecha_desde: item.fecha_desde || "",
      fecha_hasta: item.fecha_hasta || "",
      activo: isTrue(item.activo),
      observaciones: item.observaciones || "",
    })),
  };
};

function Field({ label, error, wide = false, children }) {
  const child = React.Children.only(children);
  const value = child.props.value;
  const alwaysActive =
    child.type === Select ||
    child.type === SearchableSelect ||
    child.props.type === "date" ||
    child.props.type === "number";
  const active =
    alwaysActive ||
    (value !== null && value !== undefined && String(value).trim() !== "");
  const textarea = child.type === Textarea;

  return (
    <label
      className={`entity-field ${wide ? "entity-field--wide" : ""} ${textarea ? "is-textarea" : ""} ${active ? "is-active" : ""} ${error ? "has-error" : ""}`.trim()}
    >
      {children}
      <span>{label}</span>
      {error ? <small className="entity-field__error">{error}</small> : null}
    </label>
  );
}

function Input({ value, onChange, type = "text", ...props }) {
  const openPicker = (event) => {
    if (type !== "date" || props.disabled) return;
    try {
      event.currentTarget.showPicker?.();
    } catch (_) {
      // Algunos navegadores bloquean showPicker fuera de un gesto directo.
    }
  };

  return (
    <input
      onClick={openPicker}
      placeholder=" "
      type={type}
      value={value ?? ""}
      onChange={(event) => onChange?.(event.target.value)}
      {...props}
    />
  );
}

function Textarea({ value, onChange, ...props }) {
  return (
    <textarea
      placeholder=" "
      value={value ?? ""}
      onChange={(event) => onChange?.(event.target.value)}
      {...props}
    />
  );
}

function Select({
  value,
  onChange,
  options,
  placeholder = "SELECCIONAR",
  ...props
}) {
  return (
    <select
      value={value ?? ""}
      onChange={(event) => onChange?.(event.target.value)}
      {...props}
    >
      <option value="">{placeholder}</option>
      {options.map((option) => (
        <option key={option.value} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

function Checkbox({ checked, onChange, label, disabled = false }) {
  return (
    <label className={`persona-check ${checked ? "is-selected" : ""}`.trim()}>
      <input
        checked={checked}
        disabled={disabled}
        onChange={(event) => onChange?.(event.target.checked)}
        type="checkbox"
      />
      <span>{label}</span>
    </label>
  );
}

export default function PersonaModal({
  catalogs,
  detail,
  errors,
  mode,
  onClearError,
  onClose,
  onSave,
  onValidationError,
  saving,
}) {
  const [form, setForm] = useState(() => fromDetail(detail, catalogs));
  const [activeTab, setActiveTab] = useState("general");
  const formRef = useRef(null);
  const readOnly = mode === "view";
  const existingAssociate = Boolean(detail?.asociado);
  const personId = detail?.persona?.id_persona;
  const errorKeys = useMemo(
    () => Object.keys(errors || {}).filter((key) => Boolean(errors?.[key])),
    [errors],
  );

  const errorFor = (key) => errors?.[key];
  const clearError = (path) => onClearError?.(path);
  const update = (section, field, value) => {
    const path = `${section}.${field}`;
    const sanitizedValue = sanitizeFieldValue(path, value);
    clearError(path);
    setForm((current) => ({
      ...current,
      [section]: { ...current[section], [field]: sanitizedValue },
    }));
  };

  const tabs = useMemo(
    () =>
      buildPersonSections(form.tipo_persona, {
        authorized: form.autorizados.length,
        beneficiaries: form.beneficiarios.length,
      }),
    [form.tipo_persona, form.autorizados.length, form.beneficiarios.length],
  );

  useEffect(() => {
    if (!errorKeys.length) return;
    const nextTab = tabForError(errorKeys[0]);
    if (tabs.some((tab) => tab.key === nextTab)) setActiveTab(nextTab);
  }, [errorKeys, tabs]);

  useEffect(() => {
    if (!errorKeys.length) return undefined;
    const timer = window.setTimeout(() => {
      const field = formRef.current?.querySelector(".entity-field.has-error");
      field?.scrollIntoView({ behavior: "smooth", block: "center" });
      field?.querySelector("input, select, textarea, button")?.focus?.();
    }, 80);
    return () => window.clearTimeout(timer);
  }, [activeTab, errorKeys]);

  const selectedCountry = catalogs.paises.find(
    (item) => String(item.id) === form.general.id_pais_residencia,
  );
  const localities = catalogs.localidades.filter(
    (item) =>
      !selectedCountry || String(item.id_pais) === String(selectedCountry.id),
  );
  const selectedLocality = catalogs.localidades.find(
    (item) => String(item.id) === form.general.id_localidad,
  );
  const linkablePeople = catalogs.personas_vinculables.filter(
    (item) => String(item.id) !== String(personId || ""),
  );
  const toLinkableOption = (item) => ({
    value: String(item.id),
    label: `${item.nombre}${item.documento ? ` · ${item.documento}` : ""}`,
  });
  const linkableOptions = linkablePeople.map(toLinkableOption);
  const physicalLinkableOptions = linkablePeople
    .filter((item) => item.tipo_persona === "FISICA")
    .map(toLinkableOption);

  const updateSpouse = (field, value) => {
    const path = `conyuge.${field}`;
    const sanitizedValue = sanitizeFieldValue(path, value);
    clearError(path);
    setForm((current) => ({
      ...current,
      conyuge:
        field === "id_persona_vinculada" && !sanitizedValue
          ? { id_persona_vinculada: "", fecha_desde: "", observaciones: "" }
          : { ...current.conyuge, [field]: sanitizedValue },
    }));
  };

  const addAuthorized = () => {
    clearError("autorizados");
    setForm((current) => ({
      ...current,
      autorizados: [
        ...current.autorizados,
        {
          id_persona_vinculada: "",
          alcance: "",
          operaciones_permitidas: [],
          fecha_desde: localDateValue(),
          fecha_hasta: "",
          activo: true,
          observaciones: "",
        },
      ],
    }));
  };

  const addBeneficiary = () => {
    clearError("beneficiarios");
    setForm((current) => ({
      ...current,
      beneficiarios: [
        ...current.beneficiarios,
        {
          id_persona_vinculada: "",
          porcentaje_participacion: "",
          fecha_desde: localDateValue(),
          fecha_hasta: "",
          activo: true,
          observaciones: "",
        },
      ],
    }));
  };

  const updateList = (list, index, field, value) => {
    const path = `${list}.${index}.${field}`;
    const sanitizedValue = sanitizeFieldValue(path, value);
    clearError(path);
    setForm((current) => ({
      ...current,
      [list]: current[list].map((item, itemIndex) =>
        itemIndex === index ? { ...item, [field]: sanitizedValue } : item,
      ),
    }));
  };

  const removeList = (list, index) => {
    clearError(list);
    setForm((current) => ({
      ...current,
      [list]: current[list].filter((_, itemIndex) => itemIndex !== index),
    }));
  };

  const toggleOperation = (index, operation) => {
    const current = form.autorizados[index]?.operaciones_permitidas || [];
    const next = current.includes(operation)
      ? current.filter((value) => value !== operation)
      : [...current, operation];
    updateList("autorizados", index, "operaciones_permitidas", next);
  };

  const title = useMemo(() => {
    if (mode === "create") return "Nueva persona";
    if (mode === "edit") return "Editar persona";
    return detail?.persona?.nombre_exhibicion || "Ficha de la persona";
  }, [detail, mode]);

  const modalSubtitle = readOnly
    ? `Consultá la información${detail?.asociado?.id_asociado ? ` y el legajo del socio N.º ${detail.asociado.id_asociado}` : " registrada"}.`
    : mode === "create"
      ? "Cargá los datos generales, personales y de asociación."
      : "Actualizá la ficha sin perder los vínculos ni el historial.";

  const modalTabs = tabs.map((tab) => ({
    value: tab.key,
    label: toUiLabel(tab.label),
    badge: Number.isInteger(tab.count) ? tab.count : null,
    hasError: errorKeys.some((key) => tabForError(key) === tab.key),
  }));

  const submit = (event) => {
    event.preventDefault();
    if (readOnly || saving) return;
    const validationErrors = validatePersonForm(form);
    if (Object.keys(validationErrors).length) {
      onValidationError?.(validationErrors);
      return;
    }
    onSave(form);
  };

  return (
    <CrudModal
      hideCancel={readOnly}
      hideSubmit={readOnly}
      modalClassName="personas-modal personas-modal--form"
      onClose={onClose}
      onSubmit={submit}
      open
      saving={saving}
      submitLabel={mode === "create" ? "Crear persona" : "Guardar cambios"}
      subtitle={modalSubtitle}
      title={title}
      wide
    >
      <div className="entity-form personas-modal__form" ref={formRef}>
        <EntityTabs
          ariaLabel="Secciones de la ficha"
          idPrefix="persona-tab"
          onChange={setActiveTab}
          tabs={modalTabs}
          value={activeTab}
        />
        <div className="personas-modal__content">
          {activeTab === "general" ? (
            <EntityFormPanel
              eyebrow="Datos principales"
              idPrefix="persona-tab"
              tabValue="general"
              tag={
                form.tipo_persona === "JURIDICA"
                  ? "Persona jurídica"
                  : "Persona física"
              }
              title="Información general"
            >
              <div className="persona-type-selector">
                {GLOBAL_OPTIONS.personTypes.map((option) => (
                  <button
                    className={
                      form.tipo_persona === option.value ? "is-active" : ""
                    }
                    disabled={readOnly}
                    key={option.value}
                    onClick={() => {
                      clearError("tipo_persona");
                      clearError("fisica");
                      clearError("juridica");
                      clearError("beneficiarios");
                      setForm((current) => ({
                        ...current,
                        tipo_persona: option.value,
                        beneficiarios:
                          option.value === "JURIDICA"
                            ? current.beneficiarios
                            : [],
                      }));
                    }}
                    type="button"
                  >
                    {option.label}
                  </button>
                ))}
              </div>

              <div className="persona-form-grid">
                <Field
                  label="CUIT / CUIL"
                  error={errorFor("general.cuit_cuil")}
                >
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={11}
                    onChange={(value) => update("general", "cuit_cuil", value)}
                    value={form.general.cuit_cuil}
                  />
                </Field>
                <Field
                  label="Condición frente al IVA"
                  error={errorFor("general.id_condicion_iva")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("general", "id_condicion_iva", value)
                    }
                    options={catalogToOptions(catalogs.condiciones_iva)}
                    value={form.general.id_condicion_iva}
                  />
                </Field>
                <Field
                  label="Correo electrónico"
                  error={errorFor("general.email")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("general", "email", value)}
                    type="email"
                    value={form.general.email}
                  />
                </Field>
                <Field label="Teléfono móvil" error={errorFor("general.telefono")}>
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={20}
                    onChange={(value) => update("general", "telefono", value)}
                    value={form.general.telefono}
                  />
                </Field>
                <Field label="Teléfono alternativo" error={errorFor("general.telefono_alternativo")}>
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={20}
                    onChange={(value) =>
                      update("general", "telefono_alternativo", value)
                    }
                    value={form.general.telefono_alternativo}
                  />
                </Field>
                <Field label="Domicilio" error={errorFor("general.domicilio")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("general", "domicilio", value)}
                    value={form.general.domicilio}
                  />
                </Field>
                <Field
                  label="País de residencia"
                  error={errorFor("general.id_pais_residencia")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) => {
                      clearError("general.id_pais_residencia");
                      clearError("general.id_localidad");
                      setForm((current) => ({
                        ...current,
                        general: {
                          ...current.general,
                          id_pais_residencia: value,
                          id_localidad: "",
                        },
                      }));
                    }}
                    options={catalogToOptions(catalogs.paises)}
                    value={form.general.id_pais_residencia}
                  />
                </Field>
                <Field
                  label="Localidad"
                  error={errorFor("general.id_localidad")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("general", "id_localidad", value)
                    }
                    options={catalogToOptions(localities)}
                    value={form.general.id_localidad}
                  />
                </Field>
                <Field label="Provincia">
                  <Input disabled value={selectedLocality?.provincia || ""} />
                </Field>
                <Field label="Código postal">
                  <Input
                    disabled
                    value={selectedLocality?.codigo_postal || ""}
                  />
                </Field>
                {selectedCountry?.codigo !== "AR" &&
                selectedCountry?.codigo_iso2 !== "AR" ? (
                  <Field
                    label="Localidad en el exterior"
                    error={errorFor("general.localidad_exterior")}
                  >
                    <Input
                      disabled={readOnly}
                      onChange={(value) =>
                        update("general", "localidad_exterior", value)
                      }
                      value={form.general.localidad_exterior}
                    />
                  </Field>
                ) : null}
                <Field
                  label="Zona geográfica"
                  error={errorFor("general.id_zona_geografica")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("general", "id_zona_geografica", value)
                    }
                    options={catalogToOptions(catalogs.zonas_geograficas)}
                    value={form.general.id_zona_geografica}
                  />
                </Field>
                <Field label="Actividad" error={errorFor("general.actividad")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("general", "actividad", value)}
                    value={form.general.actividad}
                  />
                </Field>
                <Field
                  label="Ingresos brutos"
                  error={errorFor("general.ingresos_brutos")}
                >
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={20}
                    onChange={(value) =>
                      update("general", "ingresos_brutos", value)
                    }
                    pattern="[0-9]*"
                    value={form.general.ingresos_brutos}
                  />
                </Field>
                <Field
                  label="Última actualización ARCA"
                  error={errorFor("general.fecha_actualizacion_arca")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("general", "fecha_actualizacion_arca", value)
                    }
                    type="date"
                    value={form.general.fecha_actualizacion_arca}
                  />
                </Field>
                <div className="persona-checks is-wide">
                  <Checkbox
                    checked={form.general.residente}
                    disabled={readOnly}
                    label="Residente"
                    onChange={(value) => update("general", "residente", value)}
                  />
                  <Checkbox
                    checked={form.general.es_pep}
                    disabled={readOnly}
                    label="Persona expuesta políticamente (PEP)"
                    onChange={(value) => update("general", "es_pep", value)}
                  />
                  <Checkbox
                    checked={form.general.sujeto_obligado}
                    disabled={readOnly}
                    label="Sujeto obligado"
                    onChange={(value) =>
                      update("general", "sujeto_obligado", value)
                    }
                  />
                </div>
                <Field
                  label="Observaciones"
                  error={errorFor("general.observaciones")}
                  wide
                >
                  <Textarea
                    disabled={readOnly}
                    onChange={(value) =>
                      update("general", "observaciones", value)
                    }
                    value={form.general.observaciones}
                  />
                </Field>
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "specific" && form.tipo_persona === "FISICA" ? (
            <EntityFormPanel
              eyebrow="Identidad"
              idPrefix="persona-tab"
              tabValue="specific"
              title="Datos de la persona física"
            >
              <div className="persona-form-grid">
                <Field label="Nombres" error={errorFor("fisica.nombres")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("fisica", "nombres", value)}
                    value={form.fisica.nombres}
                  />
                </Field>
                <Field label="Apellidos" error={errorFor("fisica.apellidos")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("fisica", "apellidos", value)}
                    value={form.fisica.apellidos}
                  />
                </Field>
                <Field label="DNI" error={errorFor("fisica.dni")}>
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={8}
                    onChange={(value) => update("fisica", "dni", value)}
                    value={form.fisica.dni}
                  />
                </Field>
                <Field
                  label="Fecha de nacimiento"
                  error={errorFor("fisica.fecha_nacimiento")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("fisica", "fecha_nacimiento", value)
                    }
                    type="date"
                    value={form.fisica.fecha_nacimiento}
                  />
                </Field>
                <Field label="Género" error={errorFor("fisica.genero")}>
                  <Select
                    disabled={readOnly}
                    onChange={(value) => update("fisica", "genero", value)}
                    options={GLOBAL_OPTIONS.genders}
                    value={form.fisica.genero}
                  />
                </Field>
                <Field
                  label="Estado civil"
                  error={errorFor("fisica.estado_civil")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("fisica", "estado_civil", value)
                    }
                    options={GLOBAL_OPTIONS.maritalStatuses}
                    value={form.fisica.estado_civil}
                  />
                </Field>
                <div className="persona-spouse-panel is-wide">
                  <div className="persona-spouse-panel__header">
                    <div>
                      <strong>Datos del cónyuge</strong>
                      <small>
                        El vínculo familiar es independiente de los permisos de la pestaña Autorizados.
                      </small>
                    </div>
                  </div>
                  <div className="persona-form-grid">
                    <Field
                      label="Cónyuge"
                      error={errorFor("conyuge.id_persona_vinculada")}
                      wide
                    >
                      <SearchableSelect
                        ariaLabel="Buscar cónyuge"
                        clearLabel="SIN CÓNYUGE"
                        disabled={readOnly}
                        emptyMessage="NO SE ENCONTRARON PERSONAS FÍSICAS"
                        onChange={(value) =>
                          updateSpouse("id_persona_vinculada", value)
                        }
                        options={physicalLinkableOptions}
                        placeholder="BUSCAR CÓNYUGE POR NOMBRE O DOCUMENTO..."
                        value={form.conyuge.id_persona_vinculada}
                      />
                    </Field>
                    <Field
                      label="Vínculo desde"
                      error={errorFor("conyuge.fecha_desde")}
                    >
                      <Input
                        disabled={
                          readOnly || !form.conyuge.id_persona_vinculada
                        }
                        onChange={(value) => updateSpouse("fecha_desde", value)}
                        type="date"
                        value={form.conyuge.fecha_desde}
                      />
                    </Field>
                    <Field
                      label="Observaciones"
                      error={errorFor("conyuge.observaciones")}
                      wide
                    >
                      <Textarea
                        disabled={
                          readOnly || !form.conyuge.id_persona_vinculada
                        }
                        onChange={(value) =>
                          updateSpouse("observaciones", value)
                        }
                        value={form.conyuge.observaciones}
                      />
                    </Field>
                  </div>
                </div>
                <Field
                  label="Nacionalidad"
                  error={errorFor("fisica.id_pais_nacionalidad")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("fisica", "id_pais_nacionalidad", value)
                    }
                    options={catalogToOptions(catalogs.paises)}
                    value={form.fisica.id_pais_nacionalidad}
                  />
                </Field>
                <Field
                  label="Relación laboral"
                  error={errorFor("fisica.id_relacion_laboral")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("fisica", "id_relacion_laboral", value)
                    }
                    options={catalogToOptions(catalogs.relaciones_laborales)}
                    value={form.fisica.id_relacion_laboral}
                  />
                </Field>
                <Field label="Profesión" error={errorFor("fisica.profesion")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("fisica", "profesion", value)}
                    value={form.fisica.profesion}
                  />
                </Field>
                <Field label="Empleador" error={errorFor("fisica.empleador")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("fisica", "empleador", value)}
                    value={form.fisica.empleador}
                  />
                </Field>
                <Field
                  label="Lugar de trabajo"
                  error={errorFor("fisica.lugar_trabajo")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("fisica", "lugar_trabajo", value)
                    }
                    value={form.fisica.lugar_trabajo}
                  />
                </Field>
                <Field label="Teléfono laboral" error={errorFor("fisica.telefono_laboral")}>
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={20}
                    onChange={(value) =>
                      update("fisica", "telefono_laboral", value)
                    }
                    value={form.fisica.telefono_laboral}
                  />
                </Field>
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "specific" && form.tipo_persona === "JURIDICA" ? (
            <EntityFormPanel
              eyebrow="Identidad"
              idPrefix="persona-tab"
              tabValue="specific"
              title="Datos de la persona jurídica"
            >
              <div className="persona-form-grid">
                <Field
                  label="Razón social"
                  error={errorFor("juridica.razon_social")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "razon_social", value)
                    }
                    value={form.juridica.razon_social}
                  />
                </Field>
                <Field
                  label="Nombre de fantasía"
                  error={errorFor("juridica.nombre_fantasia")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "nombre_fantasia", value)
                    }
                    value={form.juridica.nombre_fantasia}
                  />
                </Field>
                <Field
                  label="Tipo societario"
                  error={errorFor("juridica.id_tipo_societario")}
                >
                  <Select
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "id_tipo_societario", value)
                    }
                    options={catalogToOptions(catalogs.tipos_societarios)}
                    value={form.juridica.id_tipo_societario}
                  />
                </Field>
                <Field
                  label="Fecha de constitución"
                  error={errorFor("juridica.fecha_constitucion")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "fecha_constitucion", value)
                    }
                    type="date"
                    value={form.juridica.fecha_constitucion}
                  />
                </Field>
                <Field
                  label="Número de inscripción"
                  error={errorFor("juridica.numero_inscripcion")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "numero_inscripcion", value)
                    }
                    value={form.juridica.numero_inscripcion}
                  />
                </Field>
                <Field
                  label="Autoridad de contralor"
                  error={errorFor("juridica.autoridad_contralor")}
                >
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("juridica", "autoridad_contralor", value)
                    }
                    value={form.juridica.autoridad_contralor}
                  />
                </Field>
                <Field
                  label="Cierre de ejercicio (DD/MM)"
                  error={errorFor("juridica.fecha_cierre_ejercicio")}
                >
                  <Input
                    disabled={readOnly}
                    maxLength={5}
                    onChange={(value) =>
                      update("juridica", "fecha_cierre_ejercicio", value)
                    }
                    value={form.juridica.fecha_cierre_ejercicio}
                  />
                </Field>
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "financial" ? (
            <EntityFormPanel
              eyebrow="Perfil económico"
              idPrefix="persona-tab"
              tabValue="financial"
              title="Datos financieros"
            >
              <div className="persona-form-grid">
                <Field
                  label="Ingresos mensuales"
                  error={errorFor("financieros.ingresos_mensuales")}
                >
                  <Input
                    disabled={readOnly}
                    inputMode="decimal"
                    maxLength={19}
                    onChange={(value) =>
                      update("financieros", "ingresos_mensuales", value)
                    }
                    pattern="[0-9]*[.,]?[0-9]{0,2}"
                    value={form.financieros.ingresos_mensuales}
                  />
                </Field>
                <Field
                  label="Patrimonio estimado"
                  error={errorFor("financieros.patrimonio_estimado")}
                >
                  <Input
                    disabled={readOnly}
                    inputMode="decimal"
                    maxLength={19}
                    onChange={(value) =>
                      update("financieros", "patrimonio_estimado", value)
                    }
                    pattern="[0-9]*[.,]?[0-9]{0,2}"
                    value={form.financieros.patrimonio_estimado}
                  />
                </Field>
                <Field label="Banco" error={errorFor("financieros.banco")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) => update("financieros", "banco", value)}
                    value={form.financieros.banco}
                  />
                </Field>
                <Field label="CBU" error={errorFor("financieros.cbu")}>
                  <Input
                    disabled={readOnly}
                    inputMode="numeric"
                    maxLength={22}
                    onChange={(value) => update("financieros", "cbu", value)}
                    value={form.financieros.cbu}
                  />
                </Field>
                <Field label="Alias CBU" error={errorFor("financieros.alias_cbu")}>
                  <Input
                    disabled={readOnly}
                    onChange={(value) =>
                      update("financieros", "alias_cbu", value)
                    }
                    value={form.financieros.alias_cbu}
                  />
                </Field>
                <Field
                  label="Origen de fondos"
                  error={errorFor("financieros.origen_fondos")}
                  wide
                >
                  <Textarea
                    disabled={readOnly}
                    onChange={(value) =>
                      update("financieros", "origen_fondos", value)
                    }
                    value={form.financieros.origen_fondos}
                  />
                </Field>
                <Field
                  label="Perfil transaccional"
                  error={errorFor("financieros.perfil_transaccional")}
                  wide
                >
                  <Textarea
                    disabled={readOnly}
                    onChange={(value) =>
                      update("financieros", "perfil_transaccional", value)
                    }
                    value={form.financieros.perfil_transaccional}
                  />
                </Field>
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "associate" ? (
            <EntityFormPanel
              eyebrow="Membresía"
              idPrefix="persona-tab"
              tabValue="associate"
              title="Condición de asociado"
            >
              <div className="persona-associate-panel">
                <Checkbox
                  checked={form.asociado.es_asociado}
                  disabled={readOnly || existingAssociate}
                  label={
                    existingAssociate
                      ? `Es asociado/a · N.º ${detail.asociado.id_asociado}`
                      : "Dar de alta como asociado/a"
                  }
                  onChange={(value) => update("asociado", "es_asociado", value)}
                />
                {form.asociado.es_asociado ? (
                  <div className="persona-form-grid">
                    <Field
                      label="Fecha de ingreso"
                      error={errorFor("asociado.fecha_ingreso")}
                    >
                      <Input
                        disabled={readOnly}
                        onChange={(value) =>
                          update("asociado", "fecha_ingreso", value)
                        }
                        type="date"
                        value={form.asociado.fecha_ingreso}
                      />
                    </Field>
                    <Field
                      label="Categoría"
                      error={errorFor("asociado.id_categoria_asociado")}
                    >
                      <Select
                        disabled={readOnly}
                        onChange={(value) =>
                          update("asociado", "id_categoria_asociado", value)
                        }
                        options={catalogToOptions(
                          catalogs.categorias_asociados,
                        )}
                        value={form.asociado.id_categoria_asociado}
                      />
                    </Field>
                    <Field
                      label="Sucursal"
                      error={errorFor("asociado.id_sucursal")}
                    >
                      <Select
                        disabled={readOnly}
                        onChange={(value) =>
                          update("asociado", "id_sucursal", value)
                        }
                        options={catalogToOptions(catalogs.sucursales)}
                        value={form.asociado.id_sucursal}
                      />
                    </Field>
                    <Field label="Estado" error={errorFor("asociado.estado")}>
                      <Select
                        disabled={readOnly}
                        onChange={(value) =>
                          update("asociado", "estado", value)
                        }
                        options={GLOBAL_OPTIONS.associateStatuses}
                        value={form.asociado.estado}
                      />
                    </Field>
                    <Field
                      label="Alta en INAES"
                      error={errorFor("asociado.fecha_alta_inaes")}
                    >
                      <Input
                        disabled={readOnly}
                        onChange={(value) =>
                          update("asociado", "fecha_alta_inaes", value)
                        }
                        type="date"
                        value={form.asociado.fecha_alta_inaes}
                      />
                    </Field>
                    <div className="persona-checks">
                      <Checkbox
                        checked={form.asociado.cobra_cuota}
                        disabled={readOnly}
                        label="Cobra cuota social"
                        onChange={(value) =>
                          update("asociado", "cobra_cuota", value)
                        }
                      />
                      <Checkbox
                        checked={form.asociado.debito_automatico}
                        disabled={readOnly}
                        label="Débito automático"
                        onChange={(value) =>
                          update("asociado", "debito_automatico", value)
                        }
                      />
                    </div>
                    {form.asociado.estado === "BAJA" ? (
                      <>
                        <Field
                          label="Fecha de baja"
                          error={errorFor("asociado.fecha_baja")}
                        >
                          <Input
                            disabled={readOnly}
                            onChange={(value) =>
                              update("asociado", "fecha_baja", value)
                            }
                            type="date"
                            value={form.asociado.fecha_baja}
                          />
                        </Field>
                        <Field
                          label="Motivo de baja"
                          error={errorFor("asociado.motivo_baja")}
                        >
                          <Input
                            disabled={readOnly}
                            onChange={(value) =>
                              update("asociado", "motivo_baja", value)
                            }
                            value={form.asociado.motivo_baja}
                          />
                        </Field>
                      </>
                    ) : null}
                  </div>
                ) : (
                  <div className="persona-empty-block">
                    La persona quedará disponible para utilizarla como
                    autorizada o beneficiaria, pero no recibirá número de socio.
                  </div>
                )}
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "authorized" ? (
            <EntityFormPanel
              eyebrow="Vínculos"
              hint="La persona autorizada debe estar registrada previamente. Acá se define qué puede hacer y durante qué período."
              idPrefix="persona-tab"
              tabValue="authorized"
              tag={`${form.autorizados.length} cargado${form.autorizados.length === 1 ? "" : "s"}`}
              title="Personas autorizadas"
            >
              <div className="persona-repeater">
                {!readOnly ? (
                  <div className="persona-repeater__actions">
                    <button
                      className="global-button global-button--ghost"
                      onClick={addAuthorized}
                      type="button"
                    >
                      <GlobalIcon name="plus" size={16} />
                      Agregar autorizado
                    </button>
                  </div>
                ) : null}
                {!form.autorizados.length ? (
                  <div className="persona-empty-block">
                    No hay personas autorizadas.
                  </div>
                ) : null}
                {form.autorizados.map((item, index) => (
                  <article
                    className="persona-repeater-card"
                    key={`authorized-${index}`}
                  >
                    <div className="persona-repeater-card__title">
                      <strong>Autorizado {index + 1}</strong>
                      {!readOnly ? (
                        <button
                          aria-label="Eliminar autorizado"
                          onClick={() => removeList("autorizados", index)}
                          type="button"
                        >
                          <GlobalIcon name="close" size={16} />
                        </button>
                      ) : null}
                    </div>
                    <div className="persona-form-grid">
                      <Field
                        label="Persona"
                        error={errorFor(
                          `autorizados.${index}.id_persona_vinculada`,
                        )}
                        wide
                      >
                        <SearchableSelect
                          ariaLabel={`Buscar persona autorizada ${index + 1}`}
                          clearLabel="SIN PERSONA SELECCIONADA"
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "autorizados",
                              index,
                              "id_persona_vinculada",
                              value,
                            )
                          }
                          options={linkableOptions}
                          placeholder="BUSCAR POR NOMBRE O DOCUMENTO..."
                          value={item.id_persona_vinculada}
                        />
                      </Field>
                      <Field
                        label="Vigente desde"
                        error={errorFor(`autorizados.${index}.fecha_desde`)}
                      >
                        <Input
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "autorizados",
                              index,
                              "fecha_desde",
                              value,
                            )
                          }
                          type="date"
                          value={item.fecha_desde}
                        />
                      </Field>
                      <Field
                        label="Vigente hasta"
                        error={errorFor(`autorizados.${index}.fecha_hasta`)}
                      >
                        <Input
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "autorizados",
                              index,
                              "fecha_hasta",
                              value,
                            )
                          }
                          type="date"
                          value={item.fecha_hasta}
                        />
                      </Field>
                      <Field
                        label="Alcance"
                        error={errorFor(`autorizados.${index}.alcance`)}
                        wide
                      >
                        <Input
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList("autorizados", index, "alcance", value)
                          }
                          value={item.alcance}
                        />
                      </Field>
                      <div className="persona-operation-grid is-wide">
                        {GLOBAL_OPTIONS.authorizedOperations.map(
                          (operation) => (
                            <Checkbox
                              checked={item.operaciones_permitidas.includes(
                                operation.value,
                              )}
                              disabled={readOnly}
                              key={operation.value}
                              label={operation.label}
                              onChange={() =>
                                toggleOperation(index, operation.value)
                              }
                            />
                          ),
                        )}
                      </div>
                      <Field
                        label="Observaciones"
                        error={errorFor(`autorizados.${index}.observaciones`)}
                        wide
                      >
                        <Textarea
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "autorizados",
                              index,
                              "observaciones",
                              value,
                            )
                          }
                          value={item.observaciones}
                        />
                      </Field>
                      <div className="persona-checks is-wide">
                        <Checkbox
                          checked={item.activo}
                          disabled={readOnly}
                          label="Autorización activa"
                          onChange={(value) =>
                            updateList("autorizados", index, "activo", value)
                          }
                        />
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            </EntityFormPanel>
          ) : null}

          {activeTab === "beneficiaries" && form.tipo_persona === "JURIDICA" ? (
            <EntityFormPanel
              eyebrow="Titularidad"
              hint="Registrá las personas físicas que controlan o participan en la persona jurídica."
              idPrefix="persona-tab"
              tabValue="beneficiaries"
              tag={`${form.beneficiarios.length} cargado${form.beneficiarios.length === 1 ? "" : "s"}`}
              title="Beneficiarios finales"
            >
              <div className="persona-repeater">
                {!readOnly ? (
                  <div className="persona-repeater__actions">
                    <button
                      className="global-button global-button--ghost"
                      onClick={addBeneficiary}
                      type="button"
                    >
                      <GlobalIcon name="plus" size={16} />
                      Agregar beneficiario
                    </button>
                  </div>
                ) : null}
                {errorFor("beneficiarios") ? (
                  <div className="persona-form-error">
                    {errorFor("beneficiarios")}
                  </div>
                ) : null}
                {!form.beneficiarios.length ? (
                  <div className="persona-empty-block">
                    No hay beneficiarios finales cargados.
                  </div>
                ) : null}
                {form.beneficiarios.map((item, index) => (
                  <article
                    className="persona-repeater-card"
                    key={`beneficiary-${index}`}
                  >
                    <div className="persona-repeater-card__title">
                      <strong>Beneficiario final {index + 1}</strong>
                      {!readOnly ? (
                        <button
                          aria-label="Eliminar beneficiario"
                          onClick={() => removeList("beneficiarios", index)}
                          type="button"
                        >
                          <GlobalIcon name="close" size={16} />
                        </button>
                      ) : null}
                    </div>
                    <div className="persona-form-grid">
                      <Field
                        label="Persona física"
                        error={errorFor(
                          `beneficiarios.${index}.id_persona_vinculada`,
                        )}
                        wide
                      >
                        <SearchableSelect
                          ariaLabel={`Buscar beneficiario final ${index + 1}`}
                          clearLabel="SIN PERSONA SELECCIONADA"
                          disabled={readOnly}
                          emptyMessage="NO SE ENCONTRARON PERSONAS FÍSICAS"
                          onChange={(value) =>
                            updateList(
                              "beneficiarios",
                              index,
                              "id_persona_vinculada",
                              value,
                            )
                          }
                          options={physicalLinkableOptions}
                          placeholder="BUSCAR PERSONA FÍSICA POR NOMBRE O DOCUMENTO..."
                          value={item.id_persona_vinculada}
                        />
                      </Field>
                      <Field
                        label="Participación %"
                        error={errorFor(
                          `beneficiarios.${index}.porcentaje_participacion`,
                        )}
                      >
                        <Input
                          disabled={readOnly}
                          inputMode="decimal"
                          maxLength={6}
                          onChange={(value) =>
                            updateList(
                              "beneficiarios",
                              index,
                              "porcentaje_participacion",
                              value,
                            )
                          }
                          pattern="[0-9]*[.,]?[0-9]{0,2}"
                          value={item.porcentaje_participacion}
                        />
                      </Field>
                      <Field
                        label="Vigente desde"
                        error={errorFor(`beneficiarios.${index}.fecha_desde`)}
                      >
                        <Input
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "beneficiarios",
                              index,
                              "fecha_desde",
                              value,
                            )
                          }
                          type="date"
                          value={item.fecha_desde}
                        />
                      </Field>
                      <Field
                        label="Vigente hasta"
                        error={errorFor(`beneficiarios.${index}.fecha_hasta`)}
                      >
                        <Input
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "beneficiarios",
                              index,
                              "fecha_hasta",
                              value,
                            )
                          }
                          type="date"
                          value={item.fecha_hasta}
                        />
                      </Field>
                      <Field
                        label="Observaciones"
                        error={errorFor(`beneficiarios.${index}.observaciones`)}
                        wide
                      >
                        <Textarea
                          disabled={readOnly}
                          onChange={(value) =>
                            updateList(
                              "beneficiarios",
                              index,
                              "observaciones",
                              value,
                            )
                          }
                          value={item.observaciones}
                        />
                      </Field>
                      <div className="persona-checks is-wide">
                        <Checkbox
                          checked={item.activo}
                          disabled={readOnly}
                          label="Beneficiario activo"
                          onChange={(value) =>
                            updateList("beneficiarios", index, "activo", value)
                          }
                        />
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            </EntityFormPanel>
          ) : null}
        </div>
      </div>
    </CrudModal>
  );
}
